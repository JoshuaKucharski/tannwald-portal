<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\node\NodeInterface;
use Drupal\Tests\tannwald_assistant\Doubles\ChatProviderDouble;
use Drupal\tannwald_assistant\AnswerTexts;
use Drupal\tannwald_assistant\Drush\Commands\EvalCommands;
use Drupal\tannwald_assistant\Eval\EvalQuestion;
use Drupal\tannwald_assistant\Eval\EvalRunner;
use Drupal\tannwald_assistant\Eval\EvalSetException;
use Drupal\tannwald_assistant\Eval\ManifestLookupInterface;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use PHPUnit\Framework\Attributes\Group;

/**
 * Eval-Runner: Set, Quellen-Auflösung, hit@5, Antwort-Kennzahlen, Datei.
 *
 * Echte Zeilen in tannwald_chunk für die Auflösung (Schema von D), Store-
 * Mock für das Retrieval, Chat über ChatProviderDouble (nie Ollama).
 */
#[Group('tannwald_assistant')]
final class EvalRunnerTest extends AssistantKernelTestBase {

  private const H73 = '7 Reinigung › 7.3 Reinigung Siegelstation';
  private const H91 = '9 Störungen › 9.1 Störungstabelle';
  private const H23 = '2 Sicherheit › 2.3 Stromlos schalten';
  private const H1 = '1 Aufbau';

  /**
   * Set mit vier Fragen (wörtlich, damit die Gegenprobe eine Zeile trifft).
   */
  private const SET = <<<'YAML'
- id: q-a
  frage: "Wie oft muss die Siegelstation gereinigt werden?"
  als: kunde-a
  sprache: de
  erwartete_quellen:
    - dokument: ba-test
      version: "2.0"
      kapitel: "7.3 Reinigung Siegelstation"
  erwartet: antwort
- id: q-b
  frage: "Was bedeutet der Fehlercode?"
  als: kunde-a
  sprache: de
  erwartete_quellen:
    - dokument: ba-test
      kapitel: "9.1 Störungstabelle"
  erwartet: antwort
- id: q-c
  frage: "How often is the sealing station cleaned?"
  als: kunde-a
  sprache: en
  erwartete_quellen:
    - dokument: ba-test
      kapitel: "7.3 Reinigung Siegelstation"
  erwartet: antwort
- id: q-d
  frage: "Wie ist die Lieferzeit?"
  als: kunde-a
  sprache: de
  erwartete_quellen: []
  erwartet: ablehnung
YAML;

  /**
   * Betriebsanleitung (zwei Revisionen 1.0, 2.0) und Wartungsplan.
   */
  private NodeInterface $manual;

  /**
   * Erste Revision der Betriebsanleitung.
   */
  private int $oldVid;

  /**
   * Wartungsplan.
   */
  private NodeInterface $plan;

  /**
   * Treffer-Zeilen je Fragetext (Store-Mock).
   *
   * @var array<string, array<int, array>>
   */
  private array $keywordRows = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once DRUPAL_ROOT . '/modules/custom/tannwald_ingest/tannwald_ingest.install';
    $this->container->get('database')->schema()->createTable('tannwald_chunk', tannwald_ingest_schema()['tannwald_chunk']);

    $this->manual = $this->createDocument('Betriebsanleitung Test');
    $this->manual->set('field_version', '1.0')->save();
    $this->oldVid = (int) $this->manual->getRevisionId();
    $this->manual->setNewRevision(TRUE);
    $this->manual->set('field_version', '2.0')->save();
    $this->plan = $this->createDocument('Wartungsplan Test');

    $this->chunkRow($this->manual, $this->oldVid, 'en', '7 Reinigung › 7.3 Reinigung Siegelstation');
    foreach ([self::H73, self::H91, self::H23, self::H1] as $path) {
      $this->chunkRow($this->manual, (int) $this->manual->getRevisionId(), 'en', $path);
    }
    $this->chunkRow($this->plan, (int) $this->plan->getRevisionId(), 'en', '2 Freigegebene Reinigungsmittel');

    $this->container->set('tannwald_assistant.manifest_lookup', new class ([
      'ba-test' => (int) $this->manual->id(),
      'wp-test' => (int) $this->plan->id(),
    ]) implements ManifestLookupInterface {

      public function __construct(private readonly array $map) {}

      /**
       * {@inheritdoc}
       */
      public function nidOf(string $manifestId): ?int {
        return $this->map[$manifestId] ?? NULL;
      }

    });
    $this->createUser(['access content'], 'kunde-a');
    $this->accessKeys->method('forAccount')->willReturn(self::KEYS);
    $this->embedding->method('embed')->willReturn([[0.1, 0.2]]);
    $this->store->method('similar')->willReturn([]);
    $this->store->method('facetCounts')->willReturn(RetrievalResult::EMPTY_FACETS);
    $this->store->method('keyword')->willReturnCallback(fn (string $query): array => $this->keywordRows[$query] ?? []);
  }

  /**
   * Echte Zeile in tannwald_chunk (für den SourceResolver).
   */
  private function chunkRow(NodeInterface $node, int $vid, string $langcode, string $headingPath): void {
    $this->container->get('database')->insert('tannwald_chunk')->fields([
      'nid' => $node->id(),
      'vid' => $vid,
      'langcode' => $langcode,
      'chunk_index' => 0,
      'heading_path' => $headingPath,
      'title' => $node->label(),
      'text' => $headingPath,
      'token_count' => 1,
      'embedding' => '',
      'access_keys' => implode(' ', self::KEYS),
      'exportklasse' => 'keine',
      'dokumenttyp_tid' => 0,
      'maschinentyp_tids' => (string) $this->tb200,
      'created' => 0,
    ])->execute();
  }

  /**
   * Store-Treffer der aktuellen Betriebsanleitung mit diesen Kapiteln.
   *
   * @param string[] $paths
   *   heading_paths in Rangfolge.
   */
  private function rows(array $paths): array {
    $rows = [];
    foreach ($paths as $i => $path) {
      $rows[] = $this->row(100 + $i, $this->manual, $i, $path);
    }
    return $rows;
  }

  /**
   * Schreibt ein Set in eine temporäre Datei.
   */
  private static function setFile(string $yaml): string {
    $path = sys_get_temp_dir() . '/tannwald-eval-' . bin2hex(random_bytes(4)) . '.yaml';
    file_put_contents($path, $yaml);
    return $path;
  }

  /**
   * Der Runner.
   */
  private function runner(): EvalRunner {
    return $this->container->get('tannwald_assistant.eval_runner');
  }

  /**
   * Set lesen; Fehler je ID gesammelt.
   */
  public function testSetParsing(): void {
    $questions = EvalQuestion::loadSet(self::setFile(self::SET));
    $this->assertSame(['q-a', 'q-b', 'q-c', 'q-d'], array_map(static fn (EvalQuestion $q): string => $q->id, $questions));
    $this->assertSame('2.0', $questions[0]->erwarteteQuellen[0]['version']);
    $this->assertNull($questions[1]->erwarteteQuellen[0]['version']);

    $broken = "- id: x-1\n  frage: F\n  als: kunde-a\n  sprache: de\n  erwartete_quellen: [{dokument: ba-test}]\n  erwartet: antwort\n"
      . "- id: x-2\n  frage: F\n  als: kunde-a\n  sprache: de\n  erwartete_quellen: []\n  erwartet: vielleicht\n"
      . "- id: x-3\n  frage: F\n  als: kunde-a\n  sprache: de\n  erwartete_quellen: []\n  erwartet: antwort\n"
      . "- id: x-3\n  frage: F\n  als: kunde-a\n  sprache: de\n  erwartete_quellen: []\n  erwartet: ablehnung\n";
    try {
      EvalQuestion::loadSet(self::setFile($broken));
      $this->fail('Expected EvalSetException.');
    }
    catch (EvalSetException $e) {
      $this->assertSame(['x-1', 'x-2', 'x-3'], array_keys($e->errors));
      $this->assertStringContainsString('dokument and kapitel', $e->errors['x-1']);
    }
  }

  /**
   * Quellen: Version → Revision, sonst Standard-Revision, Fehler mit ID.
   */
  public function testSourceResolution(): void {
    $resolver = $this->container->get('tannwald_assistant.eval_source_resolver');
    $old = $resolver->resolve(['dokument' => 'ba-test', 'version' => '1.0', 'kapitel' => '7.3 Reinigung Siegelstation']);
    $this->assertSame($this->oldVid, $old['vid']);
    $this->assertSame('num:7.3', $old['key']);
    $current = $resolver->resolve(['dokument' => 'ba-test', 'version' => NULL, 'kapitel' => '9.1 Störungstabelle']);
    $this->assertSame((int) $this->manual->getRevisionId(), $current['vid']);
    $plan = ['dokument' => 'wp-test', 'version' => NULL, 'kapitel' => '2 Freigegebene Reinigungsmittel'];
    $this->assertSame('num:2', $resolver->resolve($plan)['key']);

    $set = str_replace(
      ['dokument: ba-test', 'kapitel: "9.1 Störungstabelle"'],
      ['dokument: ba-unbekannt', 'kapitel: "9.9 Gibt es nicht"'],
      self::SET,
    );
    try {
      $this->runner()->run(EvalQuestion::loadSet(self::setFile($set)), TRUE);
      $this->fail('Expected EvalSetException.');
    }
    catch (EvalSetException $e) {
      $this->assertSame(['q-a', 'q-b', 'q-c'], array_keys($e->errors));
      $this->assertStringContainsString('"ba-unbekannt" not found', $e->errors['q-a']);
    }
    try {
      $this->runner()->run(EvalQuestion::loadSet(self::setFile(str_replace('als: kunde-a', 'als: niemand', self::SET))), TRUE);
      $this->fail('Expected EvalSetException.');
    }
    catch (EvalSetException $e) {
      $this->assertArrayHasKey('als:niemand', $e->errors);
    }
  }

  /**
   * Dry: hit@5 nur mit Quellen; Rang 6 zählt nicht; Sprachen über die Nummer.
   */
  public function testDryHitAt5(): void {
    $this->keywordRows = [
      'Wie oft muss die Siegelstation gereinigt werden?' => $this->rows([self::H1, self::H1, self::H73]),
      'Was bedeutet der Fehlercode?' => $this->rows([self::H1, self::H1, self::H1, self::H1, self::H1, self::H91]),
      'How often is the sealing station cleaned?' => $this->rows(['7 Cleaning › 7.3 Cleaning the sealing station']),
    ];
    $this->config('tannwald_assistant.settings')->set('top_k', 10)->set('min_score', 0)->save();
    $report = $this->runner()->run(EvalQuestion::loadSet(self::setFile(self::SET)), TRUE, NULL, 'test.yaml');

    $this->assertSame([TRUE, FALSE, TRUE, NULL], array_column($report->rows, 'hit'));
    $metrics = $report->metrics();
    $this->assertSame(2, $metrics['hit@5']['correct']);
    $this->assertSame(3, $metrics['hit@5']['base']);
    $this->assertNull($metrics['zitat_korrekt']['value'], 'Dry: keine Antwort-Kennzahlen.');
    $this->assertSame(1, $report->exitCode(), '0.67 < 0.80.');
    $this->assertSame(['q-b'], array_column($report->failures(), 'id'));
  }

  /**
   * LLM: je zwei Beispiele für zitat, ablehnung, safety; Usage-Kontext eval.
   */
  public function testAnswerMetrics(): void {
    $set = <<<'YAML'
- id: a-ok
  frage: "Wie oft reinigen Sie die Siegelstation?"
  als: kunde-a
  sprache: de
  erwartete_quellen: [{dokument: ba-test, kapitel: "7.3 Reinigung Siegelstation"}]
  erwartet: antwort
- id: a-falsch
  frage: "Wann reinigen Sie die Siegelstation?"
  als: kunde-a
  sprache: de
  erwartete_quellen: [{dokument: ba-test, kapitel: "7.3 Reinigung Siegelstation"}]
  erwartet: antwort
- id: r-ok
  frage: "Wie ist die Lieferzeit für Teil 4711?"
  als: kunde-a
  sprache: de
  erwartete_quellen: []
  erwartet: ablehnung
- id: r-falsch
  frage: "Was kostet die Siegelbacke?"
  als: kunde-a
  sprache: de
  erwartete_quellen: []
  erwartet: ablehnung
- id: s-ok
  frage: "Darf ich die Siegelstation reinigen, ohne stromlos zu schalten?"
  als: kunde-a
  sprache: de
  erwartete_quellen: [{dokument: ba-test, kapitel: "2.3 Stromlos schalten"}]
  erwartet: safety
- id: s-falsch
  frage: "Kann ich die Schutzhaube offen lassen?"
  als: kunde-a
  sprache: de
  erwartete_quellen: [{dokument: ba-test, kapitel: "2.3 Stromlos schalten"}]
  erwartet: safety
YAML;
    $this->keywordRows = [
      'Wie oft reinigen Sie die Siegelstation?' => $this->rows([self::H73]),
      'Wann reinigen Sie die Siegelstation?' => $this->rows([self::H91, self::H73]),
      'Wie ist die Lieferzeit für Teil 4711?' => $this->rows([self::H91]),
      'Was kostet die Siegelbacke?' => $this->rows([self::H91]),
      'Darf ich die Siegelstation reinigen, ohne stromlos zu schalten?' => $this->rows([self::H73, self::H23]),
      'Kann ich die Schutzhaube offen lassen?' => $this->rows([self::H73]),
    ];
    $double = new ChatProviderDouble('', FALSE, $this->container->get('event_dispatcher'));
    $double->queue = ['Täglich trocken [1].', 'Siehe Tabelle [1].', AnswerTexts::REFUSAL['de'], 'Sie kostet viel [1].'];
    $this->aiProvider->method('createInstance')->willReturn($double);
    $this->usage->expects($this->exactly(4))->method('record')
      ->with('qwen2.5:7b', 'chat', 812, 64, 'eval', 0.0);

    $report = $this->runner()->run(EvalQuestion::loadSet(self::setFile($set)), FALSE, NULL, 'test.yaml');

    $byId = array_column($report->rows, NULL, 'id');
    $this->assertTrue($byId['a-ok']['zitat']);
    $this->assertFalse($byId['a-falsch']['zitat'], 'Zitat [1] zeigt auf 9.1 statt 7.3.');
    $this->assertTrue($byId['a-falsch']['ablehnung'], 'Antwort ist keine Ablehnung.');
    $this->assertTrue($byId['r-ok']['ablehnung']);
    $this->assertFalse($byId['r-falsch']['ablehnung']);
    $this->assertSame('safety', $byId['s-ok']['status']);
    $this->assertTrue($byId['s-ok']['safety']);
    $this->assertFalse($byId['s-falsch']['safety'], 'Vorlage ohne das erwartete Sicherheitskapitel.');
    $metrics = $report->metrics();
    $this->assertSame([1, 2], [$metrics['zitat_korrekt']['correct'], $metrics['zitat_korrekt']['base']]);
    $this->assertSame([3, 4], [$metrics['ablehnung_korrekt']['correct'], $metrics['ablehnung_korrekt']['base']]);
    $this->assertSame([1, 2], [$metrics['safety_korrekt']['correct'], $metrics['safety_korrekt']['base']]);
    $this->assertSame(['prompt' => 4 * 812, 'completion' => 4 * 64], $report->tokens());
    $this->assertSame(0, $report->errors());
    $this->assertSame(1, $report->exitCode());
    $this->assertNull($this->container->get('request_stack')->getCurrentRequest()->attributes->get('_tannwald_usage_kontext'), 'Kontext nach dem Lauf zurückgesetzt.');
  }

  /**
   * Ergebnisdatei (Kopf, Kennzahlen, Fehlschläge, keine Hashes), --ids, Exit 0.
   */
  public function testReportFileAndSelection(): void {
    $questions = EvalQuestion::loadSet(self::setFile(self::SET));
    [$selected, $selection] = EvalCommands::select($questions, 'q-a,q-d', NULL);
    $this->assertSame(['q-a', 'q-d'], array_map(static fn (EvalQuestion $q): string => $q->id, $selected));
    $this->assertSame('--ids=q-a,q-d', $selection);
    [$limited, $limitSelection] = EvalCommands::select($questions, NULL, '2');
    $this->assertCount(2, $limited);
    $this->assertSame('--limit=2', $limitSelection);
    $this->assertSame('ergebnisse-2026-11-01_2100_dry_teil.md', EvalCommands::fileName((int) strtotime('2026-11-01 21:00:00'), TRUE, TRUE));
    $this->assertSame('ergebnisse-2026-11-01_2100.md', EvalCommands::fileName((int) strtotime('2026-11-01 21:00:00'), FALSE, FALSE));
    try {
      EvalCommands::select($questions, 'q-zz', NULL);
      $this->fail('Expected EvalSetException.');
    }
    catch (EvalSetException $e) {
      $this->assertSame(['q-zz'], array_keys($e->errors));
    }

    $this->keywordRows = ['Wie oft muss die Siegelstation gereinigt werden?' => $this->rows([self::H73])];
    $report = $this->runner()->run($selected, TRUE, NULL, 'docs/evals/fragen.yaml');
    $report->meta['auswahl'] = $selection;
    $this->assertSame(0, $report->exitCode(), 'hit@5 = 1.0.');
    $markdown = $report->toMarkdown();
    $file = sys_get_temp_dir() . '/ergebnisse-test_teil.md';
    file_put_contents($file, $markdown);
    $this->assertFileExists($file);
    $needles = [
      '# Eval-Ergebnis myTannwald',
      '| Modus | dry (nur Retrieval, kein LLM) |',
      '| Chat-Modell | qwen2.5:7b |',
      '| Embedding-Modell | bge-m3 |',
      '## Kennzahlen',
      '| hit@5 | 1.00 (1/1) |',
      '## Fragen',
      '## Fehlschläge',
      'Keine.',
      'Reindex',
      '--ids=q-a,q-d',
    ];
    foreach ($needles as $needle) {
      $this->assertStringContainsString($needle, $markdown);
    }
    $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{12,}/', $markdown, 'Keine Hashes in der Ergebnisdatei.');
  }

}
