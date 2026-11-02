<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;

/**
 * Gemeinsame Wiederholungslogik der Tannwald-Queues.
 *
 * Fehler: bis MAX_ATTEMPTS Versuche insgesamt; der Job wird mit attempts + 1
 * neu eingestellt (Watchdog warning), beim letzten Fehlschlag Watchdog error
 * und verwerfen. So enden Cron und tannwald:queue-run gleich.
 */
abstract class RetryingQueueWorkerBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Versuche insgesamt, bevor ein Job verworfen wird.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * Ergebnis des letzten processItem(): ok|skipped|retry|failed.
   */
  protected string $lastOutcome = 'ok';

  /**
   * Konstruktor.
   *
   * @param array $configuration
   *   Plugin-Konfiguration.
   * @param string $plugin_id
   *   Plugin-ID (= Queue-Name).
   * @param mixed $plugin_definition
   *   Plugin-Definition.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue-Factory (Wiederholungen, Folgejobs).
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger tannwald_ingest.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected QueueFactory $queueFactory,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Verarbeitet einen Job; wirft bei Fehlern, die wiederholt werden sollen.
   *
   * @return string
   *   ok|skipped.
   */
  abstract protected function run(array $item): string;

  /**
   * Prüft die Payload; ungültige Jobs werden ohne Wiederholung verworfen.
   */
  abstract protected function isValid(array $item): bool;

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $item = (array) $data;
    if (!$this->isValid($item)) {
      $this->logger->error('Invalid @queue payload dropped: @payload', [
        '@queue' => $this->getPluginId(),
        '@payload' => json_encode($item),
      ]);
      $this->lastOutcome = 'failed';
      return;
    }
    try {
      $this->lastOutcome = $this->run($item);
    }
    catch (\Throwable $e) {
      $attempts = (int) ($item['attempts'] ?? 0) + 1;
      $context = [
        '@queue' => $this->getPluginId(),
        '@nid' => $item['nid'] ?? '-',
        '@vid' => $item['vid'] ?? '-',
        '@lang' => $item['langcode'] ?? '-',
        '@attempt' => $attempts,
        '@max' => static::MAX_ATTEMPTS,
        '@message' => mb_substr($e->getMessage(), 0, 300),
      ];
      if ($attempts < static::MAX_ATTEMPTS) {
        $item['attempts'] = $attempts;
        $this->queueFactory->get($this->getPluginId())->createItem($item);
        $this->logger->warning('@queue job for node @nid revision @vid (@lang) failed, attempt @attempt of @max, requeued: @message', $context);
        $this->lastOutcome = 'retry';
        return;
      }
      $this->logger->error('@queue job for node @nid revision @vid (@lang) failed after @attempt attempts, dropped: @message', $context);
      $this->lastOutcome = 'failed';
    }
  }

  /**
   * Ergebnis des letzten processItem() (für tannwald:queue-run).
   */
  public function lastOutcome(): string {
    return $this->lastOutcome;
  }

}
