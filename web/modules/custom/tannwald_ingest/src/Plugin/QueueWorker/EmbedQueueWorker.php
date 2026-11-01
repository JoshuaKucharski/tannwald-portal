<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
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
 * weil replaceForRevision() vorher löscht. Fehler: bis 3 Versuche (Zähler
 * attempts im neu eingestellten Job), dann Watchdog error und verwerfen.
 */
#[QueueWorker(
  id: self::QUEUE,
  title: new TranslatableMarkup('Tannwald chunk embedding'),
  cron: ['time' => 60],
)]
final class EmbedQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Name der Queue.
   */
  public const QUEUE = 'tannwald_embed';

  /**
   * Indexierte Bundles.
   */
  public const BUNDLES = ['dokument', 'bulletin', 'zusammenfassung'];

  /**
   * Versuche insgesamt, bevor ein Job verworfen wird.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * Ergebnis des letzten processItem(): ok|skipped|retry|failed.
   */
  protected string $lastOutcome = 'ok';

  /**
   * @param array $configuration
   *   Plugin-Konfiguration.
   * @param string $plugin_id
   *   Plugin-ID.
   * @param mixed $plugin_definition
   *   Plugin-Definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity-Typ-Manager.
   * @param \Drupal\tannwald_ingest\Chunker $chunker
   *   Chunker.
   * @param \Drupal\tannwald_ingest\Embedding\EmbeddingService $embedding
   *   Embeddings.
   * @param \Drupal\tannwald_ingest\Store\ChunkStoreInterface $store
   *   Chunk-Store.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue-Factory (Wiederholungen).
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger tannwald_ingest.
   * @param object|null $entitlement
   *   tannwald_access.entitlement (getRealmsForNode()); optional, weil die
   *   Klasse final ist und Tests ein Double einsetzen.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Chunker $chunker,
    protected EmbeddingService $embedding,
    protected ChunkStoreInterface $store,
    protected QueueFactory $queueFactory,
    protected LoggerInterface $logger,
    protected ?object $entitlement = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('tannwald_ingest.chunker'),
      $container->get('tannwald_ingest.embedding'),
      $container->get('tannwald_ingest.chunk_store'),
      $container->get('queue'),
      $container->get('logger.channel.tannwald_ingest'),
      $container->has('tannwald_access.entitlement') ? $container->get('tannwald_access.entitlement') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $item = (array) $data;
    $nid = (int) ($item['nid'] ?? 0);
    $action = (string) ($item['action'] ?? '');
    if ($nid < 1 || !in_array($action, ['index', 'delete'], TRUE)) {
      $this->logger->error('Invalid tannwald_embed payload dropped: @payload', ['@payload' => json_encode($item)]);
      $this->lastOutcome = 'failed';
      return;
    }
    try {
      $this->lastOutcome = $action === 'delete' ? $this->delete($item) : $this->index($item);
    }
    catch (\Throwable $e) {
      $attempts = (int) ($item['attempts'] ?? 0) + 1;
      $context = [
        '@nid' => $nid,
        '@vid' => $item['vid'] ?? '-',
        '@lang' => $item['langcode'] ?? '-',
        '@attempt' => $attempts,
        '@max' => self::MAX_ATTEMPTS,
        '@message' => mb_substr($e->getMessage(), 0, 300),
      ];
      if ($attempts < self::MAX_ATTEMPTS) {
        $item['attempts'] = $attempts;
        $this->queueFactory->get(self::QUEUE)->createItem($item);
        $this->logger->warning('Indexing node @nid revision @vid (@lang) failed, attempt @attempt of @max, requeued: @message', $context);
        $this->lastOutcome = 'retry';
        return;
      }
      $this->logger->error('Indexing node @nid revision @vid (@lang) failed after @attempt attempts, job dropped: @message', $context);
      $this->lastOutcome = 'failed';
    }
  }

  /**
   * Ergebnis des letzten processItem() (für tannwald:queue-run).
   */
  public function lastOutcome(): string {
    return $this->lastOutcome;
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
      foreach ($source->get('field_maschinentyp') as $type) {
        $types[] = (int) $type->target_id;
      }
    }
    return [
      'exportklasse' => $source->hasField('field_exportklasse') ? (string) ($source->get('field_exportklasse')->value ?: 'keine') : 'keine',
      'dokumenttyp_tid' => $source->bundle() === 'dokument' && $source->hasField('field_dokumenttyp') ? (int) $source->get('field_dokumenttyp')->target_id : 0,
      'maschinentyp_tids' => array_values(array_unique(array_filter($types))),
    ];
  }

}
