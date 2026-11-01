<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Kernel;

use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker;
use PHPUnit\Framework\Attributes\Group;

/**
 * Queue tannwald_embed mit gemocktem AI-Provider (nie Ollama).
 */
#[Group('tannwald_ingest')]
class EmbedQueueWorkerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'node', 'taxonomy', 'file', 'key', 'ai',
    'workflows', 'content_moderation', 'datetime', 'tannwald_ingest',
  ];

  /**
   * Keys, die das Entitlement-Double für jeden Node liefert.
   */
  protected const KEYS = ['type:TB200+export:klasse_1'];

  /**
   * Batch-Grössen der Provider-Aufrufe.
   *
   * @var int[]
   */
  protected array $calls = [];

  /**
   * Wirft der Provider-Double einen Fehler?
   */
  protected bool $failing = FALSE;

  /**
   * Term-IDs.
   */
  protected int $typeTid;

  /**
   * Term-ID Dokumenttyp.
   */
  protected int $docTypeTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if ($this->container->get('database')->driver() !== 'mysql') {
      $this->markTestSkipped('FULLTEXT needs MariaDB/MySQL (SIMPLETEST_DB).');
    }
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'node']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('tannwald_ingest', ['tannwald_chunk', 'tannwald_usage']);
    \Drupal::moduleHandler()->loadInclude('tannwald_ingest', 'install');
    tannwald_ingest_ensure_fulltext($this->container->get('database'));

    $this->createContentModel();
    $this->mockProvider();
    $this->container->set('tannwald_access.entitlement', new class (self::KEYS) {

      public function __construct(protected array $keys) {}

      /**
       * Wie EntitlementService::getRealmsForNode().
       */
      public function getRealmsForNode(NodeInterface $node): array {
        return $this->keys;
      }

    });
  }

  /**
   * Bundle dokument mit body und den drei Metadaten-Feldern.
   */
  protected function createContentModel(): void {
    NodeType::create(['type' => 'dokument', 'name' => 'Dokument'])->save();
    Vocabulary::create(['vid' => 'maschinentyp', 'name' => 'Maschinentyp'])->save();
    Vocabulary::create(['vid' => 'dokumenttyp', 'name' => 'Dokumenttyp'])->save();
    $type = Term::create(['vid' => 'maschinentyp', 'name' => 'TB-200']);
    $type->save();
    $this->typeTid = (int) $type->id();
    $docType = Term::create(['vid' => 'dokumenttyp', 'name' => 'Betriebsanleitung']);
    $docType->save();
    $this->docTypeTid = (int) $docType->id();

    $fields = [
      'body' => ['type' => 'text_with_summary', 'settings' => []],
      'field_exportklasse' => [
        'type' => 'list_string',
        'settings' => [
          'allowed_values' => ['keine' => 'keine', 'klasse_1' => 'klasse_1', 'klasse_2' => 'klasse_2'],
        ],
      ],
      'field_dokumenttyp' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
      ],
      'field_maschinentyp' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
        'cardinality' => -1,
      ],
    ];
    foreach ($fields as $name => $definition) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $definition['type'],
        'settings' => $definition['settings'],
        'cardinality' => $definition['cardinality'] ?? 1,
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'dokument', 'label' => $name])->save();
    }
  }

  /**
   * Ersetzt ai.provider durch ein Double mit deterministischen Vektoren.
   */
  protected function mockProvider(): void {
    $proxy = $this->getMockBuilder(ProviderProxy::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['__call'])
      ->getMock();
    $proxy->method('__call')->willReturnCallback(function (string $name, array $arguments) {
      if ($name === 'getConfiguredModels') {
        return ['bge_m3_latest' => 'bge-m3:latest'];
      }
      $this->assertSame('embeddingsCollection', $name);
      $this->assertSame('bge-m3:latest', $arguments[1], 'Model ID from ai.settings is translated to the raw name.');
      /** @var \Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput $input */
      $input = $arguments[0];
      $this->calls[] = $input->getPromptCount();
      if ($this->failing) {
        throw new \RuntimeException('Provider down (test double).');
      }
      $vectors = array_map([self::class, 'hashVector'], $input->getPrompts());
      $tokens = array_sum(array_map('mb_strlen', $input->getPrompts()));
      $raw = ['model' => 'bge-m3:latest', 'usage' => ['prompt_tokens' => $tokens]];
      return new EmbeddingsCollectionOutput($vectors, $raw, []);
    });
    $this->config('ai.settings')
      ->set('default_providers.embeddings', ['provider_id' => 'ollama', 'model_id' => 'bge_m3_latest'])
      ->save();
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('createInstance')->with('ollama')->willReturn($proxy);
    $this->container->set('ai.provider', $manager);
  }

  /**
   * Deterministischer 1024er-Vektor aus dem Text-Hash.
   *
   * @return float[]
   *   Vektor.
   */
  public static function hashVector(string $text): array {
    $seed = crc32($text);
    $vector = [];
    for ($i = 0; $i < 1024; $i++) {
      $vector[] = sin($seed + $i * 0.37);
    }
    return $vector;
  }

  /**
   * Ein Dokument mit zwei Kapiteln und einer Störungstabelle.
   */
  protected function createDocument(bool $published, string $extra = ''): NodeInterface {
    $html = '<p>Einleitung zur Betriebsanleitung.</p>'
      . '<h2 id="kapitel-9-stoerungen">9 Störungen</h2><p>Störungen werden am Bedienpanel angezeigt.</p>'
      . '<h3>9.1 Störungstabelle</h3><table><thead><tr><th>Code</th><th>Abhilfe</th></tr></thead>'
      . '<tbody><tr><td>E-4711</td><td>Teil 4711 tauschen</td></tr></tbody></table>' . $extra;
    $node = Node::create([
      'type' => 'dokument',
      'title' => 'Betriebsanleitung TB-200',
      'langcode' => 'en',
      'status' => $published ? 1 : 0,
      'body' => ['value' => $html, 'format' => 'plain_text'],
      'field_exportklasse' => 'klasse_1',
      'field_dokumenttyp' => $this->docTypeTid,
      'field_maschinentyp' => [$this->typeTid],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Verarbeitet tannwald_embed bis leer.
   *
   * @return string[]
   *   Ergebnisse je Job.
   */
  protected function runQueue(): array {
    $queue = $this->container->get('queue')->get(EmbedQueueWorker::QUEUE);
    /** @var \Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker $worker */
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(EmbedQueueWorker::QUEUE);
    $outcomes = [];
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
      $outcomes[] = $worker->lastOutcome();
    }
    return $outcomes;
  }

  /**
   * Stellt einen index-Job für die aktuelle Revision (Sprache en) ein.
   */
  protected function queueIndexJob(NodeInterface $node): void {
    $this->container->get('queue')->get(EmbedQueueWorker::QUEUE)->createItem([
      'nid' => (int) $node->id(),
      'vid' => (int) $node->getRevisionId(),
      'langcode' => 'en',
      'action' => 'index',
    ]);
  }

  /**
   * Zählt Zeilen einer Tabelle.
   */
  protected function rows(string $table, array $conditions = []): int {
    $query = $this->container->get('database')->select($table, 't');
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Index einer veröffentlichten Revision: Chunks mit Keys und Metadaten.
   */
  public function testIndexPublishedRevision(): void {
    $node = $this->createDocument(TRUE);
    $this->assertSame(1, $this->container->get('queue')->get(EmbedQueueWorker::QUEUE)->numberOfItems(), 'Hook queued one job, no synchronous embedding.');
    $this->assertSame([], $this->calls);

    $this->assertSame(['ok'], $this->runQueue());
    $rows = $this->container->get('database')->query('SELECT * FROM {tannwald_chunk} ORDER BY chunk_index')->fetchAll();
    $this->assertCount(3, $rows);
    $this->assertSame((int) $node->id(), (int) $rows[0]->nid);
    $this->assertSame((int) $node->getRevisionId(), (int) $rows[0]->vid);
    $this->assertSame('Betriebsanleitung TB-200', $rows[0]->heading_path);
    $this->assertSame('9 Störungen › 9.1 Störungstabelle', $rows[2]->heading_path);
    $this->assertSame('type:TB200+export:klasse_1', $rows[2]->access_keys);
    $this->assertSame('klasse_1', $rows[2]->exportklasse);
    $this->assertSame($this->docTypeTid, (int) $rows[2]->dokumenttyp_tid);
    $this->assertSame((string) $this->typeTid, $rows[2]->maschinentyp_tids);
    $this->assertSame([3], $this->calls, 'One batch for three chunks.');

    $usage = $this->container->get('database')->query('SELECT * FROM {tannwald_usage}')->fetchAll();
    $this->assertCount(1, $usage);
    $this->assertSame('bge-m3', $usage[0]->modell);
    $this->assertSame('embedding', $usage[0]->operation);
    $this->assertSame('ingest', $usage[0]->kontext);
    $this->assertSame(0, (int) $usage[0]->completion_tokens);
    $this->assertGreaterThan(0, (int) $usage[0]->prompt_tokens);

    $hits = $this->container->get('tannwald_ingest.chunk_store')->keyword('4711', self::KEYS, 5);
    $this->assertSame(2, $hits[0]['chunk_index']);
    $similar = $this->container->get('tannwald_ingest.chunk_store')->similar(self::hashVector($rows[1]->text), self::KEYS, 1);
    $this->assertEqualsWithDelta(1.0, $similar[0]['score'], 1e-5);
    $this->assertSame(1, $similar[0]['chunk_index']);

    // Zweiter Lauf desselben Jobs (Cron und Seeder): gleiche Zahlen.
    $this->queueIndexJob($node);
    $this->assertSame(['ok'], $this->runQueue());
    $this->assertSame(3, $this->rows('tannwald_chunk'));
  }

  /**
   * Unveröffentlichte Revisionen erzeugen keine Zeilen; Unpublish löscht.
   */
  public function testDraftAndUnpublish(): void {
    $node = $this->createDocument(FALSE);
    $this->assertSame(['ok'], $this->runQueue(), 'Delete job for the unpublished revision.');
    $this->assertSame(0, $this->rows('tannwald_chunk'));
    $this->assertSame([], $this->calls, 'No embedding for unpublished revisions.');

    // Ein index-Job für eine unveröffentlichte Revision schreibt nichts.
    $this->queueIndexJob($node);
    $this->assertSame(['skipped'], $this->runQueue());
    $this->assertSame(0, $this->rows('tannwald_chunk'));

    $node->setPublished()->setNewRevision();
    $node->save();
    $publishedVid = (int) $node->getRevisionId();
    $this->runQueue();
    $this->assertSame(3, $this->rows('tannwald_chunk', ['vid' => $publishedVid]));

    // Zweite veröffentlichte Revision (Version 3.2): beide indexiert.
    $node->setNewRevision();
    $node->save();
    $this->runQueue();
    $this->assertSame(6, $this->rows('tannwald_chunk'));

    // Neue Standard-Revision unveröffentlicht (archiviert): alle Chunks des
    // Nodes weg, auch die der älteren veröffentlichten Revision.
    $node->setUnpublished()->setNewRevision();
    $node->save();
    $this->runQueue();
    $this->assertSame(0, $this->rows('tannwald_chunk'));
  }

  /**
   * Delete-Jobs und Node-Löschung räumen.
   */
  public function testDelete(): void {
    $node = $this->createDocument(TRUE);
    $this->runQueue();
    $this->assertSame(3, $this->rows('tannwald_chunk'));
    $queue = $this->container->get('queue')->get(EmbedQueueWorker::QUEUE);
    $queue->createItem(['nid' => (int) $node->id(), 'vid' => (int) $node->getRevisionId(), 'action' => 'delete']);
    $this->assertSame(['ok'], $this->runQueue());
    $this->assertSame(0, $this->rows('tannwald_chunk'));

    $this->queueIndexJob($node);
    $this->runQueue();
    $this->assertSame(3, $this->rows('tannwald_chunk'));
    $node->delete();
    $this->assertSame(0, $this->rows('tannwald_chunk'), 'Node delete removes chunks immediately.');
  }

  /**
   * Fehler-Provider: drei Versuche, dann verworfen; keine Zeilen.
   */
  public function testRetries(): void {
    $this->failing = TRUE;
    $this->createDocument(TRUE);
    $this->assertSame(['retry', 'retry', 'failed'], $this->runQueue());
    $this->assertSame([3, 3, 3], $this->calls, 'Three attempts in total.');
    $this->assertSame(0, $this->container->get('queue')->get(EmbedQueueWorker::QUEUE)->numberOfItems());
    $this->assertSame(0, $this->rows('tannwald_chunk'));
    $this->assertSame(0, $this->rows('tannwald_usage'));
  }

  /**
   * Ohne Entitlement-Dienst: Chunks mit leeren Keys, also unsichtbar.
   */
  public function testMissingEntitlementWritesEmptyKeys(): void {
    $this->container->set('tannwald_access.entitlement', NULL);
    $this->createDocument(TRUE);
    $this->runQueue();
    $this->assertSame(3, $this->rows('tannwald_chunk', ['access_keys' => '']));
    $this->assertSame([], $this->container->get('tannwald_ingest.chunk_store')->keyword('4711', self::KEYS, 5));
  }

  /**
   * EmbeddingService: Batches zu 16, Usage je Batch, Kontext, Dimension.
   */
  public function testEmbeddingBatches(): void {
    $texts = [];
    for ($i = 0; $i < 20; $i++) {
      $texts[] = "Text $i";
    }
    $service = $this->container->get('tannwald_ingest.embedding');
    $vectors = $service->embed($texts);
    $this->assertCount(20, $vectors);
    $this->assertCount(1024, $vectors[19]);
    $this->assertSame(self::hashVector('Text 19'), $vectors[19]);
    $this->assertSame([16, 4], $this->calls);
    $this->assertSame(2, $this->rows('tannwald_usage', ['kontext' => 'ingest']));
    $service->embed(['Frage'], 'assistent');
    $this->assertSame(1, $this->rows('tannwald_usage', ['kontext' => 'assistent']));
    $this->assertSame([], $service->embed([]));
  }

}
