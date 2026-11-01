<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_assistant\Retriever;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Retriever: Keys, zweite Schranke, min_score, matched_by, volltext, Maschine.
 *
 * Store, Entitlement (feste Keys) und Embedding sind Mocks; Nodes und
 * $node->access('view') sind echt.
 */
#[Group('tannwald_assistant')]
final class RetrieverAccessFilterTest extends AssistantKernelTestBase {

  /**
   * Nutzer mit "access content".
   */
  private UserInterface $viewer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->viewer = $this->createUser(['access content'], 'kunde-test');
    $this->embedding->method('embed')->willReturn([[0.1, 0.2, 0.3]]);
  }

  /**
   * Der Retriever-Dienst (nach dem Belegen der Mocks).
   */
  private function retriever(): Retriever {
    return $this->container->get('tannwald_assistant.retriever');
  }

  /**
   * Der Store sieht nur die Keys des Nutzers; ein Treffer ohne Zugriff fliegt.
   */
  public function testForeignKeysNeverReachStoreAndHitWithoutAccessIsDropped(): void {
    $visible = $this->createDocument('Betriebsanleitung TB-200');
    $foreign = $this->createDocument('Schaltplan HF fremd', FALSE);
    $this->accessKeys->method('forAccount')->willReturn(self::KEYS);
    $this->store->expects($this->once())->method('keyword')
      ->with('Wie oft muss die Siegelstation gereinigt werden?', self::KEYS, 20)
      ->willReturn([$this->row(1, $foreign), $this->row(2, $visible)]);
    $this->store->expects($this->once())->method('similar')
      ->with([0.1, 0.2, 0.3], self::KEYS, 20)
      ->willReturn([$this->row(1, $foreign)]);
    $this->store->method('facetCounts')->willReturn([
      'dokumenttyp' => [5 => 1],
      'maschinentyp' => [],
      'sprache' => ['en' => 1],
    ]);
    $this->embedding->expects($this->once())->method('embed')->with(['Wie oft muss die Siegelstation gereinigt werden?'], 'assistent');
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('without view access'), $this->callback(static fn (array $context): bool => $context['@nid'] === (int) $foreign->id()));

    $result = $this->retriever()->retrieve('Wie oft muss die Siegelstation gereinigt werden?', $this->viewer, NULL);

    $this->assertSame([(int) $visible->id()], array_column($result->hits, 'nid'));
    $this->assertNotContains((int) $foreign->id(), array_column($result->hits, 'nid'));
    $hit = $result->hits[0];
    $this->assertSame(1, $hit['n'], 'n wird nach dem Rauswurf neu vergeben.');
    $this->assertSame('3.2', $hit['version']);
    $this->assertSame('keyword', $hit['matched_by']);
    $this->assertStringEndsWith('/node/' . $visible->id() . '#kapitel-7-3-reinigung-siegelstation', $hit['url']);
    $this->assertSame('de', $result->langcode);
    $this->assertSame(RetrievalResult::MODE_HYBRID, $result->modus);
    $this->assertSame(['en' => 1], $result->facets['sprache']);
  }

  /**
   * Min_score (RRF): Rang 7 einer einzelnen Liste fällt unter 0.015.
   */
  public function testMinScore(): void {
    $this->config('tannwald_assistant.settings')->set('top_k', 10)->save();
    $doc = $this->createDocument('Betriebsanleitung TB-200');
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
      $rows[] = $this->row($i, $doc, $i);
    }
    $this->accessKeys->method('forAccount')->willReturn(self::KEYS);
    $this->store->method('keyword')->willReturn($rows);
    $this->store->method('facetCounts')->willReturn(RetrievalResult::EMPTY_FACETS);

    $result = $this->retriever()->retrieve('Siegelstation reinigen', $this->viewer, NULL, RetrievalResult::MODE_FULLTEXT);
    $this->assertSame([1, 2, 3, 4, 5, 6], array_column($result->hits, 'chunk_id'));
    $this->assertEqualsWithDelta(1 / 66, $result->hits[5]['score'], 1e-12);

    $this->config('tannwald_assistant.settings')->set('min_score', 0.0162)->save();
    $strict = $this->retriever()->retrieve('Siegelstation reinigen', $this->viewer, NULL, RetrievalResult::MODE_FULLTEXT);
    $this->assertSame([1], array_column($strict->hits, 'chunk_id'), 'Nur Rang 1 (1/61 = 0.01639) liegt über 0.0162.');
  }

  /**
   * Matched_by both/keyword/vector, Top-k-Schnitt nach den Facetten.
   */
  public function testMatchedByAndTopK(): void {
    $this->config('tannwald_assistant.settings')->set('top_k', 3)->save();
    $doc = $this->createDocument('Betriebsanleitung TB-200');
    $this->accessKeys->method('forAccount')->willReturn(self::KEYS);
    $this->store->method('keyword')->willReturn([
      $this->row(10, $doc, 0),
      $this->row(11, $doc, 1),
      $this->row(14, $doc, 4),
    ]);
    $this->store->method('similar')->willReturn([$this->row(11, $doc, 1), $this->row(12, $doc, 2)]);
    $this->store->expects($this->once())->method('facetCounts')
      ->with($this->callback(static function (array $ids): bool {
        sort($ids);
        return $ids === [10, 11, 12, 14];
      }))
      ->willReturn(RetrievalResult::EMPTY_FACETS);

    $result = $this->retriever()->retrieve('Folie reisst beim Siegeln', $this->viewer, NULL);
    $this->assertSame([11, 10, 12], array_column($result->hits, 'chunk_id'));
    $this->assertSame(['both', 'keyword', 'vector'], array_column($result->hits, 'matched_by'));
    $this->assertSame([1, 2, 3], array_column($result->hits, 'n'));
  }

  /**
   * Volltext: kein Embedding, kein Vektor-Zweig, nur keyword.
   */
  public function testFulltextWithoutVector(): void {
    $doc = $this->createDocument('Ersatzteilkatalog TB-200');
    $this->accessKeys->method('forAccount')->willReturn(self::KEYS);
    $this->embedding->expects($this->never())->method('embed');
    $this->store->expects($this->never())->method('similar');
    $this->store->method('keyword')->willReturn([$this->row(1, $doc), $this->row(2, $doc, 1)]);
    $this->store->method('facetCounts')->willReturn(RetrievalResult::EMPTY_FACETS);

    $result = $this->retriever()->retrieve('E-4711', $this->viewer, NULL, RetrievalResult::MODE_FULLTEXT);
    $this->assertSame(RetrievalResult::MODE_FULLTEXT, $result->modus);
    $this->assertSame(['keyword', 'keyword'], array_column($result->hits, 'matched_by'));
  }

  /**
   * Leere Keys: leeres Ergebnis ohne Store- und Embedding-Aufruf.
   */
  public function testEmptyKeysGiveEmptyResult(): void {
    $this->accessKeys->method('forAccount')->willReturn([]);
    $this->store->expects($this->never())->method('keyword');
    $this->store->expects($this->never())->method('similar');
    $this->embedding->expects($this->never())->method('embed');

    $result = $this->retriever()->retrieve('Wie oft muss die Siegelstation gereinigt werden?', $this->viewer, NULL);
    $this->assertTrue($result->isEmpty());
    $this->assertSame(RetrievalResult::EMPTY_FACETS, $result->facets);
  }

  /**
   * Maschine_nid: Typ der Maschine oder deren Seriennummer, sonst raus.
   */
  public function testMachineFilter(): void {
    $machine = $this->createMachine('TB200-2021-0417', $this->tb200);
    $other = $this->createMachine('TB200-2019-0001', $this->tb200);
    $typeDoc = $this->createDocument('Betriebsanleitung TB-200');
    $kt500Doc = $this->createDocument('Betriebsanleitung KT-500');
    $ownSerialDoc = $this->createDocument('Anlagendokumentation TB200-2021-0417', TRUE, (int) $machine->id());
    $otherSerialDoc = $this->createDocument('Anlagendokumentation TB200-2019-0001', TRUE, (int) $other->id());
    $this->accessKeys->method('forAccount')->willReturn(self::KEYS);
    $this->store->method('keyword')->willReturn([
      $this->row(1, $kt500Doc, 0, '1 Allgemein', [$this->kt500]),
      $this->row(2, $otherSerialDoc),
      $this->row(3, $typeDoc),
      $this->row(4, $ownSerialDoc),
    ]);
    $this->store->method('facetCounts')->willReturn(RetrievalResult::EMPTY_FACETS);

    $result = $this->retriever()->retrieve('Abnahmeprotokoll', $this->viewer, (int) $machine->id(), RetrievalResult::MODE_FULLTEXT);
    $this->assertSame([(int) $typeDoc->id(), (int) $ownSerialDoc->id()], array_column($result->hits, 'nid'));

    $unknown = $this->retriever()->retrieve('Abnahmeprotokoll', $this->viewer, 999999, RetrievalResult::MODE_FULLTEXT);
    $this->assertTrue($unknown->isEmpty(), 'Unbekannte Maschine → leer.');
  }

}
