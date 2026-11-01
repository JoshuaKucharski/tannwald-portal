<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush-Befehle für die Ingestion (tannwald:reindex, :queue-run, :chunk-stats).
 */
final class IngestCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'database')]
    protected Connection $database,
    #[Autowire(service: 'queue')]
    protected QueueFactory $queueFactory,
    #[Autowire(service: 'plugin.manager.queue_worker')]
    protected QueueWorkerManagerInterface $workerManager,
  ) {
    parent::__construct();
  }

  /**
   * Queue index jobs for all published revisions of the indexed bundles.
   *
   * Stellt Jobs für alle veröffentlichten Revisionen der drei Bundles ein: je
   * Node zuerst ein delete-Job (räumt Reste), dann index-Jobs je
   * veröffentlichter Revision und Sprache (node_field_revision.status = 1).
   */
  #[CLI\Command(name: 'tannwald:reindex')]
  #[CLI\Option(name: 'nid', description: 'Only this node ID.')]
  #[CLI\Usage(name: 'drush tannwald:reindex', description: 'Queue all published revisions of dokument, bulletin and zusammenfassung.')]
  public function reindex(array $options = ['nid' => self::REQ]): void {
    $query = $this->database->select('node_field_revision', 'r');
    $query->innerJoin('node', 'n', 'n.nid = r.nid');
    $query->fields('r', ['nid', 'vid', 'langcode'])
      ->condition('n.type', EmbedQueueWorker::BUNDLES, 'IN')
      ->condition('r.status', 1)
      ->orderBy('r.nid')
      ->orderBy('r.vid')
      ->orderBy('r.langcode');
    if (!empty($options['nid'])) {
      $query->condition('r.nid', (int) $options['nid']);
    }
    $rows = $query->execute()->fetchAll();
    $queue = $this->queueFactory->get(EmbedQueueWorker::QUEUE);
    $nodes = [];
    foreach ($rows as $row) {
      if (!isset($nodes[$row->nid])) {
        $nodes[$row->nid] = TRUE;
        $queue->createItem(['nid' => (int) $row->nid, 'action' => 'delete']);
      }
      $queue->createItem([
        'nid' => (int) $row->nid,
        'vid' => (int) $row->vid,
        'langcode' => (string) $row->langcode,
        'action' => 'index',
      ]);
    }
    if (!empty($options['nid']) && $nodes === []) {
      // Node ohne veröffentlichte Revision: nur aufräumen.
      $queue->createItem(['nid' => (int) $options['nid'], 'action' => 'delete']);
    }
    $this->logger()->success(dt('Queued @jobs index jobs for @nodes nodes (tannwald_embed now holds @total items).', [
      '@jobs' => count($rows),
      '@nodes' => count($nodes),
      '@total' => $queue->numberOfItems(),
    ]));
  }

  /**
   * Process the tannwald_embed queue until it is empty.
   *
   * Verarbeitet tannwald_embed bis leer; Zählung ok/übersprungen/Wiederholung/
   * Fehler und Dauer.
   */
  #[CLI\Command(name: 'tannwald:queue-run')]
  #[CLI\Usage(name: 'drush tannwald:queue-run', description: 'Process tannwald_embed until empty and report counts and duration.')]
  public function queueRun(): void {
    $start = microtime(TRUE);
    $queue = $this->queueFactory->get(EmbedQueueWorker::QUEUE);
    /** @var \Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker $worker */
    $worker = $this->workerManager->createInstance(EmbedQueueWorker::QUEUE);
    $counts = ['ok' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
    while (is_object($item = $queue->claimItem(300))) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
      $counts[$worker->lastOutcome()]++;
      $data = (array) $item->data;
      $this->logger()->info(sprintf('%s nid=%s vid=%s %s: %s', $data['action'] ?? '?', $data['nid'] ?? '?', $data['vid'] ?? '-', $data['langcode'] ?? '-', $worker->lastOutcome()));
    }
    $this->logger()->success(dt('tannwald_embed empty: @ok ok, @skipped skipped, @retry retried, @failed failed in @seconds s.', [
      '@ok' => $counts['ok'],
      '@skipped' => $counts['skipped'],
      '@retry' => $counts['retry'],
      '@failed' => $counts['failed'],
      '@seconds' => round(microtime(TRUE) - $start, 1),
    ]));
  }

  /**
   * Show tannwald_chunk rows per bundle and language.
   *
   * Zeilen in tannwald_chunk je Bundle und Sprache (Hilfe für Berichte).
   */
  #[CLI\Command(name: 'tannwald:chunk-stats')]
  #[CLI\FieldLabels(labels: [
    'bundle' => 'Bundle',
    'langcode' => 'Language',
    'documents' => 'Documents',
    'chunks' => 'Chunks',
    'tokens' => 'Tokens',
  ])]
  public function chunkStats(array $options = ['format' => 'table']): RowsOfFields {
    $result = $this->database->query('SELECT COALESCE(n.type, :none) AS bundle, c.langcode, COUNT(DISTINCT c.nid, c.vid) AS documents, COUNT(*) AS chunks, SUM(c.token_count) AS tokens
      FROM {tannwald_chunk} c LEFT JOIN {node} n ON n.nid = c.nid
      GROUP BY bundle, c.langcode ORDER BY bundle, c.langcode', [':none' => '(none)']);
    $rows = [];
    foreach ($result as $row) {
      $rows[] = (array) $row;
    }
    return new RowsOfFields($rows);
  }

}
