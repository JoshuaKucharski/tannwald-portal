<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\tannwald_assistant\Access\AccessKeysInterface;
use Drupal\tannwald_ingest\Embedding\EmbeddingService;
use Drupal\tannwald_ingest\Store\ChunkStoreInterface;
use Drupal\tannwald_ingest\Usage\UsageRecorder;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Aufbau der Kernel-Tests: Fremddienste als Doubles, nie Ollama.
 *
 * Tannwald_access, tannwald_ingest und ai werden nicht aktiviert; ihre
 * Dienste sind synthetisch und werden je Test mit Mocks belegt (Store,
 * Embedding, Usage, ai.provider) bzw. über die eigene Naht
 * tannwald_assistant.access_keys (Entitlement mit festen Keys).
 */
abstract class AssistantKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Keys des Testnutzers.
   */
  protected const KEYS = ['type:TB200+export:keine', 'type:TB200+export:klasse_1'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'tannwald_assistant',
  ];

  /**
   * Entitlement-Naht mit festen Keys.
   */
  protected AccessKeysInterface&MockObject $accessKeys;

  /**
   * ChunkStore-Mock.
   */
  protected ChunkStoreInterface&MockObject $store;

  /**
   * Embedding-Mock (nie Ollama).
   */
  protected EmbeddingService&MockObject $embedding;

  /**
   * Usage-Mock.
   */
  protected UsageRecorder&MockObject $usage;

  /**
   * Mock für ai.provider (liefert ChatProviderDouble).
   */
  protected PluginManagerInterface&MockObject $aiProvider;

  /**
   * Logger-Kanal tannwald_assistant.
   */
  protected LoggerInterface&MockObject $logger;

  /**
   * Term-ID TB-200.
   */
  protected int $tb200;

  /**
   * Term-ID KT-500.
   */
  protected int $kt500;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $foreign = [
      'tannwald_access.entitlement',
      'tannwald_ingest.chunk_store',
      'tannwald_ingest.embedding',
      'tannwald_ingest.usage',
      'ai.provider',
    ];
    foreach ($foreign as $id) {
      if (!$container->hasDefinition($id)) {
        $container->register($id)->setSynthetic(TRUE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('tannwald_assistant', ['tannwald_assistant_log']);
    $this->installConfig(['filter', 'node', 'tannwald_assistant']);
    // Die uid 1 umgeht jede Zugriffsprüfung; ein Platzhalter belegt sie.
    $this->createUser([], 'placeholder-uid-1');
    $this->createContentModel();

    $this->accessKeys = $this->createMock(AccessKeysInterface::class);
    $this->store = $this->createMock(ChunkStoreInterface::class);
    $this->embedding = $this->createMock(EmbeddingService::class);
    $this->usage = $this->createMock(UsageRecorder::class);
    $this->aiProvider = $this->createMock(PluginManagerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->container->set('tannwald_assistant.access_keys', $this->accessKeys);
    $this->container->set('tannwald_ingest.chunk_store', $this->store);
    $this->container->set('tannwald_ingest.embedding', $this->embedding);
    $this->container->set('tannwald_ingest.usage', $this->usage);
    $this->container->set('ai.provider', $this->aiProvider);
    $this->container->set('logger.channel.tannwald_assistant', $this->logger);
  }

  /**
   * Bundles dokument und maschine mit den Feldern, die der Retriever liest.
   */
  protected function createContentModel(): void {
    NodeType::create(['type' => 'dokument', 'name' => 'Dokument'])->save();
    NodeType::create(['type' => 'maschine', 'name' => 'Maschine'])->save();
    Vocabulary::create(['vid' => 'maschinentyp', 'name' => 'Maschinentyp'])->save();
    $tb200 = Term::create(['vid' => 'maschinentyp', 'name' => 'TB-200']);
    $tb200->save();
    $this->tb200 = (int) $tb200->id();
    $kt500 = Term::create(['vid' => 'maschinentyp', 'name' => 'KT-500']);
    $kt500->save();
    $this->kt500 = (int) $kt500->id();

    $fields = [
      'field_version' => ['dokument', 'string', []],
      'field_seriennummer' => ['dokument', 'entity_reference', ['target_type' => 'node']],
      'field_maschinentyp' => ['maschine', 'entity_reference', ['target_type' => 'taxonomy_term']],
    ];
    foreach ($fields as $name => [$bundle, $type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $name === 'field_maschinentyp' ? -1 : 1,
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle])->save();
    }
  }

  /**
   * Legt ein Dokument an (veröffentlicht, Version 3.2).
   */
  protected function createDocument(string $title, bool $published = TRUE, ?int $serialNid = NULL): NodeInterface {
    $values = [
      'type' => 'dokument',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'field_version' => '3.2',
    ];
    if ($serialNid !== NULL) {
      $values['field_seriennummer'] = $serialNid;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Legt eine Maschine eines Typs an.
   */
  protected function createMachine(string $serial, int $typeTid): NodeInterface {
    $node = Node::create(['type' => 'maschine', 'title' => $serial, 'status' => 1, 'field_maschinentyp' => [$typeTid]]);
    $node->save();
    return $node;
  }

  /**
   * Treffer-Zeile des ChunkStore zu einem Dokument.
   */
  protected function row(int $id, NodeInterface $node, int $chunkIndex = 0, string $headingPath = '7 Reinigung › 7.3 Reinigung Siegelstation', ?array $typeTids = NULL): array {
    return [
      'id' => $id,
      'nid' => (int) $node->id(),
      'vid' => (int) $node->getRevisionId(),
      'langcode' => $node->language()->getId(),
      'chunk_index' => $chunkIndex,
      'heading_path' => $headingPath,
      'title' => $node->label(),
      'text' => 'Text von Chunk ' . $id,
      'exportklasse' => 'keine',
      'dokumenttyp_tid' => 5,
      'maschinentyp_tids' => $typeTids ?? [$this->tb200],
      'score' => 1.0,
    ];
  }

}
