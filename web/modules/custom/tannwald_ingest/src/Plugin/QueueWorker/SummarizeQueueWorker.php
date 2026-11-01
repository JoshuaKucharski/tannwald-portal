<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Summary\SummaryGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Erzeugt die Zusammenfassung einer veröffentlichten Dokument-Revision.
 *
 * Payload {nid, vid, langcode, attempts?}; idempotent über den Generator
 * (gibt es die Sprache der Revision schon, entsteht nichts). Wiederholungen:
 * Basisklasse (3 Versuche).
 */
#[QueueWorker(
  id: self::QUEUE,
  title: new TranslatableMarkup('Tannwald document summaries'),
  cron: ['time' => 120],
)]
final class SummarizeQueueWorker extends RetryingQueueWorkerBase {

  /**
   * Name der Queue.
   */
  public const QUEUE = 'tannwald_summarize';

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
   *   Queue-Factory (Wiederholungen).
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger tannwald_ingest.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity-Typ-Manager.
   * @param \Drupal\tannwald_ingest\Summary\SummaryGenerator $generator
   *   Generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Konfiguration (summaries_enabled).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    QueueFactory $queueFactory,
    LoggerInterface $logger,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SummaryGenerator $generator,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('tannwald_ingest.summary_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function isValid(array $item): bool {
    return (int) ($item['nid'] ?? 0) > 0 && (int) ($item['vid'] ?? 0) > 0 && (string) ($item['langcode'] ?? '') !== '';
  }

  /**
   * {@inheritdoc}
   */
  protected function run(array $item): string {
    if (!$this->configFactory->get('tannwald_ingest.settings')->get('summaries_enabled')) {
      return 'skipped';
    }
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $storage->loadRevision((int) $item['vid']);
    $langcode = (string) $item['langcode'];
    if (!$revision instanceof NodeInterface
      || (int) $revision->id() !== (int) $item['nid']
      || $revision->bundle() !== 'dokument'
      || !$revision->hasTranslation($langcode)
      || !$revision->getTranslation($langcode)->isPublished()) {
      return 'skipped';
    }
    return $this->generator->generate($revision, $langcode) === NULL ? 'skipped' : 'ok';
  }

}
