<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tannwald_bulletin\Mailer\BulletinMailerInterface;
use Drupal\tannwald_bulletin\RecipientResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue tannwald_notify: Mails zu einem freigegebenen Bulletin.
 *
 * Payload {bulletin_nid}; Wiederholung intern {bulletin_nid, uids, attempt}:
 * fehlgeschlagene Empfänger kommen bis zu drei Mal zurück in die Queue,
 * danach Watchdog.
 */
#[QueueWorker(
  id: 'tannwald_notify',
  title: new TranslatableMarkup('Tannwald bulletin notifications'),
  cron: ['time' => 30],
)]
final class NotifyWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Höchstzahl der Versuche je Empfänger.
   */
  public const MAX_ATTEMPTS = 3;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RecipientResolver $recipients,
    protected readonly BulletinMailerInterface $mailer,
    protected readonly QueueFactory $queueFactory,
    protected readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('tannwald_bulletin.recipients'),
      $container->get(BulletinMailerInterface::class),
      $container->get('queue'),
      $container->get('logger.channel.tannwald_bulletin'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = (int) ($data['bulletin_nid'] ?? 0);
    $attempt = (int) ($data['attempt'] ?? 1);
    $onlyUids = isset($data['uids']) ? array_map('intval', (array) $data['uids']) : NULL;
    $bulletin = $nid > 0 ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if (!$bulletin instanceof NodeInterface || $bulletin->bundle() !== 'bulletin' || !$bulletin->isPublished()) {
      $this->logger->notice('Bulletin @nid not published, no mails.', ['@nid' => $nid]);
      return;
    }
    $sent = 0;
    $failed = [];
    foreach ($this->recipients->forBulletin($bulletin) as $uid => $recipient) {
      if ($onlyUids !== NULL && !in_array($uid, $onlyUids, TRUE)) {
        continue;
      }
      if ($this->mailer->notify($bulletin, $recipient['account'], $recipient['maschinen'])) {
        $sent++;
      }
      else {
        $failed[] = $uid;
      }
    }
    $this->logger->info('@count mails for bulletin @nid (attempt @attempt).', [
      '@count' => $sent,
      '@nid' => $nid,
      '@attempt' => $attempt,
    ]);
    if ($failed === []) {
      return;
    }
    if ($attempt < self::MAX_ATTEMPTS) {
      $this->queueFactory->get('tannwald_notify')->createItem([
        'bulletin_nid' => $nid,
        'uids' => $failed,
        'attempt' => $attempt + 1,
      ]);
      return;
    }
    $this->logger->error('Bulletin @nid: no mail delivered to users @uids after @max attempts.', [
      '@nid' => $nid,
      '@uids' => implode(', ', $failed),
      '@max' => self::MAX_ATTEMPTS,
    ]);
  }

}
