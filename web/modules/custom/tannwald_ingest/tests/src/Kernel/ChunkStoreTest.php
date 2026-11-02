<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tannwald_ingest\Store\ChunkStoreInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Chunk-Store gegen MariaDB: FULLTEXT, Cosinus, Vorfilter, Facetten.
 */
#[Group('tannwald_ingest')]
class ChunkStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'file', 'key', 'ai', 'workflows', 'content_moderation', 'tannwald_ingest',
  ];

  /**
   * Keys eines TB-200-Kunden ohne Exportfreigaben.
   */
  protected const KEYS_TB200 = ['type:TB200+export:keine'];

  /**
   * Keys eines Kunden mit KT-500.
   */
  protected const KEYS_KT500 = ['type:KT500+export:keine'];

  /**
   * Der Store.
   */
  protected ChunkStoreInterface $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if ($this->container->get('database')->driver() !== 'mysql') {
      $this->markTestSkipped('FULLTEXT needs MariaDB/MySQL (SIMPLETEST_DB).');
    }
    $this->installSchema('tannwald_ingest', ['tannwald_chunk', 'tannwald_usage']);
    // hook_install() läuft über installSchema() nicht.
    \Drupal::moduleHandler()->loadInclude('tannwald_ingest', 'install');
    tannwald_ingest_ensure_fulltext($this->container->get('database'));
    $this->store = $this->container->get('tannwald_ingest.chunk_store');
  }

  /**
   * Einheitsvektor mit Gewichten an einzelnen Achsen.
   *
   * @param array<int, float> $axes
   *   Achse => Wert.
   *
   * @return float[]
   *   1024 Werte.
   */
  protected static function vector(array $axes): array {
    $vector = array_fill(0, 1024, 0.0);
    foreach ($axes as $axis => $value) {
      $vector[$axis] = $value;
    }
    return $vector;
  }

  /**
   * Ein Chunk nach Vertrag.
   */
  protected static function chunk(int $index, string $heading, string $text, array $axes, array $keys, int $dokumenttyp = 5, array $types = [1], string $title = 'Betriebsanleitung TB-200'): array {
    return [
      'chunk_index' => $index,
      'heading_path' => $heading,
      'title' => $title,
      'text' => $text,
      'token_count' => (int) ceil(mb_strlen($text) / 4),
      'embedding' => self::vector($axes),
      'access_keys' => $keys,
      'exportklasse' => 'keine',
      'dokumenttyp_tid' => $dokumenttyp,
      'maschinentyp_tids' => $types,
    ];
  }

  /**
   * Legt an: TB-200 de und fr (nid 10), KT-500 de (nid 20).
   */
  protected function seed(): void {
    $this->store->replaceForRevision(10, 100, 'de', [
      self::chunk(0, '9 Störungen', '9 Störungen. Dieses Kapitel beschreibt Störungen am Bedienpanel und das Ersatzteil im Katalog.', [0 => 1.0], self::KEYS_TB200),
      self::chunk(1, '9 Störungen › 9.1 Störungstabelle', "9.1 Störungstabelle\nCode | Störung | Abhilfe\nE-4711 | Folienriss an der Siegelstation | Teil 4711 tauschen", [1 => 1.0], self::KEYS_TB200),
      self::chunk(2, '9 Störungen › 9.4 Störungen an der Siegelstation', '9.4 Störungen an der Siegelstation. Die Siegelnaht ist undicht, wenn die Temperatur zu tief ist.', [2 => 1.0], self::KEYS_TB200),
    ]);
    $this->store->replaceForRevision(10, 100, 'fr', [
      self::chunk(0, '9 Pannes', '9 Pannes. Ce chapitre décrit les pannes et la pièce 4711.', [3 => 1.0], self::KEYS_TB200),
    ]);
    $this->store->replaceForRevision(20, 200, 'de', [
      self::chunk(0, '9 Störungen', 'Störungstabelle KT-500: E-4711 bedeutet Kartonmangel, Teil 4711 prüfen.', [1 => 1.0], self::KEYS_KT500, 6, [3], 'Betriebsanleitung KT-500'),
    ]);
  }

  /**
   * Zeilen in tannwald_chunk.
   */
  protected function rowCount(array $conditions = []): int {
    $query = $this->container->get('database')->select('tannwald_chunk', 'c');
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * ReplaceForRevision ist idempotent; Löschen je Revision und Node.
   */
  public function testReplaceAndDelete(): void {
    $this->seed();
    $this->assertSame(5, $this->rowCount());
    $this->seed();
    $this->assertSame(5, $this->rowCount(), 'Second replace leaves the same row count.');

    // Leere Chunk-Liste räumt genau eine Sprache einer Revision.
    $this->store->replaceForRevision(10, 100, 'fr', []);
    $this->assertSame(3, $this->rowCount(['nid' => 10]));

    // Neue Revision lässt die alte stehen; deleteForRevision trifft nur eine.
    $this->store->replaceForRevision(10, 101, 'de', [self::chunk(0, 'Neu', 'Neue Revision.', [4 => 1.0], self::KEYS_TB200)]);
    $this->store->deleteForRevision(10, 100);
    $this->assertSame(0, $this->rowCount(['vid' => 100]));
    $this->assertSame(1, $this->rowCount(['vid' => 101]));

    $this->store->deleteForNode(10);
    $this->assertSame(0, $this->rowCount(['nid' => 10]));
    $this->assertSame(1, $this->rowCount(['nid' => 20]));

    // Vektor wird verlustfrei gespeichert (float32 LE, 4096 Bytes).
    $blob = $this->container->get('database')->query('SELECT embedding FROM {tannwald_chunk} WHERE nid = 20')->fetchField();
    $this->assertSame(4096, strlen($blob));
  }

  /**
   * Keyword: gewichtete FULLTEXT-Suche mit access_keys-Vorfilter.
   */
  public function testKeyword(): void {
    $this->seed();

    $hits = $this->store->keyword('4711', self::KEYS_TB200, 5);
    $this->assertNotEmpty($hits);
    $this->assertSame([10, 1], [$hits[0]['nid'], $hits[0]['chunk_index']], 'The table row with 4711 comes first.');
    $this->assertSame('9 Störungen › 9.1 Störungstabelle', $hits[0]['heading_path']);
    $this->assertSame([1], $hits[0]['maschinentyp_tids']);
    $this->assertGreaterThan(0, $hits[0]['score']);
    $this->assertNotContains(20, array_column($hits, 'nid'), 'KT-500 chunk is filtered by access keys.');
    $this->assertSame([
      'id', 'nid', 'vid', 'langcode', 'chunk_index', 'heading_path', 'title', 'text',
      'exportklasse', 'dokumenttyp_tid', 'maschinentyp_tids', 'score',
    ], array_keys($hits[0]));

    // Operatorzeichen entfernt: „E-4711“ negiert nicht, sondern trifft 4711.
    $hits = $this->store->keyword('E-4711', self::KEYS_TB200, 5);
    $this->assertSame([10, 1], [$hits[0]['nid'], $hits[0]['chunk_index']]);

    // ODER-Semantik: „Lieferzeit Teil 4711“ findet die Tabelle.
    $hits = $this->store->keyword('Lieferzeit Teil 4711', self::KEYS_TB200, 5);
    $this->assertSame([10, 1], [$hits[0]['nid'], $hits[0]['chunk_index']]);

    // Gewichte: Treffer im heading_path (×2) schlägt Treffer nur im Text.
    $hits = $this->store->keyword('Siegelstation', self::KEYS_TB200, 5);
    $this->assertSame(2, $hits[0]['chunk_index']);

    // Fremde Keys und leere Keys liefern nichts aus TB-200.
    $foreign = $this->store->keyword('4711', ['type:TB400+export:keine'], 5);
    $this->assertSame([], $foreign);
    $this->assertSame([], $this->store->keyword('4711', [], 5));
    $this->assertSame([], $this->store->keyword('4711', ['', ' '], 5));
    // Teilstring eines Keys trifft nicht (Key-Grenzen per Leerzeichen).
    $this->assertSame([], $this->store->keyword('4711', ['type:TB200'], 5));

    $kt = $this->store->keyword('4711', self::KEYS_KT500, 5);
    $this->assertSame([20], array_unique(array_column($kt, 'nid')));
  }

  /**
   * Similar: Cosinus-Reihenfolge und Vorfilter in SQL.
   */
  public function testSimilar(): void {
    $this->seed();
    // Anfrage nahe Achse 2, etwas Achse 0; Achse 1 ist der KT-500-Doppelgänger.
    $query = self::vector([2 => 1.0, 0 => 0.5, 1 => 0.2]);
    $hits = $this->store->similar($query, self::KEYS_TB200, 3);
    $this->assertSame([2, 0, 1], array_column($hits, 'chunk_index'));
    $this->assertSame([10, 10, 10], array_column($hits, 'nid'));
    $this->assertEqualsWithDelta(1.0 / sqrt(1.29), $hits[0]['score'], 1e-6);
    $this->assertGreaterThan($hits[1]['score'], $hits[0]['score']);
    $this->assertGreaterThan($hits[2]['score'], $hits[1]['score']);

    // Der KT-500-Chunk hat exakt den Anfragevektor, bleibt aber gefiltert.
    $hits = $this->store->similar(self::vector([1 => 1.0]), self::KEYS_TB200, 10);
    $this->assertNotContains(20, array_column($hits, 'nid'), 'Prefilter excludes foreign keys.');
    $this->assertSame(1, $hits[0]['chunk_index']);
    $hits = $this->store->similar(self::vector([1 => 1.0]), self::KEYS_KT500, 10);
    $this->assertSame([20], array_column($hits, 'nid'));
    $this->assertSame([], $this->store->similar(self::vector([1 => 1.0]), [], 10));
  }

  /**
   * Facetten zählen Dokumente (nid, vid, langcode), nicht Chunks.
   */
  public function testFacetCounts(): void {
    $this->seed();
    $ids = $this->container->get('database')->query('SELECT id FROM {tannwald_chunk}')->fetchCol();
    $facets = $this->store->facetCounts($ids);
    $this->assertSame([5 => 2, 6 => 1], $facets['dokumenttyp']);
    $this->assertSame([1 => 2, 3 => 1], $facets['maschinentyp']);
    $this->assertSame(['de' => 2, 'fr' => 1], $facets['sprache']);
    $this->assertSame(['dokumenttyp' => [], 'maschinentyp' => [], 'sprache' => []], $this->store->facetCounts([]));
  }

}
