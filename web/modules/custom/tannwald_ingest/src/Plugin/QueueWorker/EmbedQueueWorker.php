<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Chunker;
use Drupal\tannwald_ingest\Embedding\EmbeddingService;
use Drupal\tannwald_ingest\Store\ChunkStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chunkt, embeddet und speichert eine Node-Revision (Queue tannwald_embed).
 *
 * Payload {nid, vid, langcode, action: index|delete, attempts?}; idempotent,
 * weil replaceForRevision() vorher löscht. Nach erfolgreichem index eines
 * Dokuments folgt ein Job tannwald_summarize (nie für Bulletins oder
 * Zusammenfassungen — keine Schleife). Wiederholungen: Basisklasse.
 */
#[QueueWorker(
  id: self::QUEUE,
  title: new TranslatableMarkup('Tannwald chunk embedding'),
  cron: ['time' => 60],
)]
final class EmbedQueueWorker extends RetryingQueueWorkerBase {

  /**
   * Name der Queue.
   */
  public const QUEUE = 'tannwald_embed';

  /**
   * Indexierte Bundles.
   */
  public const BUNDLES = ['dokument', 'bulletin', 'zusammenfassung'];

  /**
   * Konstruktor.
   *
   * @param array $configuration
   *   Plugin-Konfiguration.
   * @param string $plugin_id
   *   Plugin-ID.
   * @param mixed $plugin_definition
   *   Plugin-Definition.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue-Factory (Wiederholungen, Folgejob tannwald_summarize).
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger tannwald_ingest.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity-Typ-Manager.
   * @param \Drupal\tannwald_ingest\Chunker $chunker
   *   Chunker.
   * @param \Drupal\tannwald_ingest\Embedding\EmbeddingService $embedding
   *   Embeddings.
   * @param \Drupal\tannwald_ingest\Store\ChunkStoreInterface $store
   *   Chunk-Store.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Konfiguration (tannwald_ingest.settings:summaries_enabled).
   * @param object|null $entitlement
   *   tannwald_access.entitlement (getRealmsForNode()); optional, weil die
   *   Klasse final ist und Tests ein Double einsetzen.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    QueueFactory $queueFactory,
    LoggerInterface $logger,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Chunker $chunker,
    protected EmbeddingService $embedding,
    protected ChunkStoreInterface $store,
    protected ConfigFactoryInterface $configFactory,
    protected ?object $entitlement = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $queueFactory, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('queue'),
      $container->get('logger.channel.tannwald_ingest'),
      $container->get('entity_type.manager'),
      $container->get('tannwald_ingest.chunker'),
      $container->get('tannwald_ingest.embedding'),
      $container->get('tannwald_ingest.chunk_store'),
      $container->get('config.factory'),
      $container->has('tannwald_access.entitlement') ? $container->get('tannwald_access.entitlement') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function isValid(array $item): bool {
    return (int) ($item['nid'] ?? 0) > 0 && in_array($item['action'] ?? '', ['index', 'delete'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function run(array $item): string {
    return $item['action'] === 'delete' ? $this->delete($item) : $this->index($item);
  }

  /**
   * Action delete: Revision+Sprache, Revision oder ganzer Node.
   */
  protected function delete(array $item): string {
    $nid = (int) $item['nid'];
    $vid = (int) ($item['vid'] ?? 0);
    $langcode = (string) ($item['langcode'] ?? '');
    if ($vid < 1) {
      $this->store->deleteForNode($nid);
    }
    elseif ($langcode !== '') {
      $this->store->replaceForRevision($nid, $vid, $langcode, []);
    }
    else {
      $this->store->deleteForRevision($nid, $vid);
    }
    return 'ok';
  }

  /**
   * Action index: nur veröffentlichte Revisionen der drei Bundles.
   */
  protected function index(array $item): string {
    $nid = (int) $item['nid'];
    $vid = (int) ($item['vid'] ?? 0);
    $langcode = (string) ($item['langcode'] ?? '');
    if ($vid < 1 || $langcode === '') {
      throw new \InvalidArgumentException('Action index needs vid and langcode.');
    }
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $storage->loadRevision($vid);
    if (!$revision instanceof NodeInterface || (int) $revision->id() !== $nid) {
      // Revision gibt es nicht mehr: Reste entfernen.
      $this->store->deleteForRevision($nid, $vid);
      return 'skipped';
    }
    if (!in_array($revision->bundle(), self::BUNDLES, TRUE)) {
      return 'skipped';
    }
    if (!$revision->hasTranslation($langcode)) {
      $this->store->replaceForRevision($nid, $vid, $langcode, []);
      return 'skipped';
    }
    $node = $revision->getTranslation($langcode);
    // Moderationsstatus published setzt status = 1 an dieser Revision.
    if (!$node->isPublished()) {
      $this->store->replaceForRevision($nid, $vid, $langcode, []);
      return 'skipped';
    }

    $title = (string) $node->label();
    $chunks = $this->chunker->chunk((string) ($node->get('body')->value ?? ''), $title);
    if ($chunks === []) {
      $this->store->replaceForRevision($nid, $vid, $langcode, []);
      return 'skipped';
    }
    $keys = $this->accessKeys($node);
    $meta = $this->metadata($node);
    $vectors = $this->embedding->embed(array_column($chunks, 'text'));
    $rows = [];
    foreach ($chunks as $i => $chunk) {
      $rows[] = $chunk + [
        'title' => $title,
        'embedding' => $vectors[$i],
        'access_keys' => $keys,
      ] + $meta;
    }
    $this->store->replaceForRevision($nid, $vid, $langcode, $rows);
    if ($node->bundle() === 'dokument' && $this->configFactory->get('tannwald_ingest.settings')->get('summaries_enabled')) {
      $this->queueFactory->get(SummarizeQueueWorker::QUEUE)->createItem([
        'nid' => $nid,
        'vid' => $vid,
        'langcode' => $langcode,
      ]);
    }
    return 'ok';
  }

  /**
   * Access-Keys genau wie EntitlementService::getRealmsForNode().
   *
   * Fehlt der Dienst, bleiben die Keys leer: die Chunks werden geschrieben,
   * treffen aber nie (leere Keys sind unsichtbar).
   *
   * @return string[]
   *   Keys.
   */
  protected function accessKeys(NodeInterface $node): array {
    if ($this->entitlement === NULL || !method_exists($this->entitlement, 'getRealmsForNode')) {
      $this->logger->warning('tannwald_access.entitlement missing: chunks of node @nid are stored without access keys (invisible).', ['@nid' => $node->id()]);
      return [];
    }
    return array_values(array_map('strval', $this->entitlement->getRealmsForNode($node)));
  }

  /**
   * Exportklasse, Dokumenttyp und Maschinentypen aus den Feldern.
   *
   * Bulletin: dokumenttyp_tid 0; Zusammenfassung: Werte des referenzierten
   * Dokuments.
   */
  protected function metadata(NodeInterface $node): array {
    $source = $node;
    if ($node->bundle() === 'zusammenfassung' && $node->hasField('field_dokument')) {
      $document = $node->get('field_dokument')->entity;
      if ($document instanceof NodeInterface) {
        $source = $document->hasTranslation($node->language()->getId()) ? $document->getTranslation($node->language()->getId()) : $document;
      }
    }
    $types = [];
    if ($source->hasField('field_maschinentyp')) {
      foreach ($source->get('field_maschinentyp')->getValue() as $value) {
        $types[] = (int) ($value['target_id'] ?? 0);
      }
    }
    return [
      'exportklasse' => $source->hasField('field_exportklasse') ? (string) ($source->get('field_exportklasse')->value ?: 'keine') : 'keine',
      'dokumenttyp_tid' => $source->bundle() === 'dokument' && $source->hasField('field_dokumenttyp') ? (int) $source->get('field_dokumenttyp')->target_id : 0,
      'maschinentyp_tids' => array_values(array_unique(array_filter($types))),
    ];
  }

}
