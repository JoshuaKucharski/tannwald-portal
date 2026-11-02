<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Limit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tannwald_assistant\AnswerTexts;
use Psr\Log\LoggerInterface;

/**
 * Monatslimit des Assistenten (Pflichtenheft §6.3, §8; FA-REP-02, TC-12).
 *
 * Verbrauch = Summe prompt_tokens + completion_tokens aus tannwald_usage seit
 * Monatsbeginn (Zeitzone der Site), alle Kontexte. Ab 80 % genau eine
 * Warnmail je Monat an alle aktiven portal_admin (State
 * tannwald_assistant.limit_warned.<YYYY-MM>); ab 100 % search_only.
 * Monatslimit 0 = unbegrenzt. Verbrauch 60 s gecacht (Tag CACHE_TAG; der
 * UsageTracker invalidiert ihn nach jedem Chat).
 */
final class LimitService implements LimitCheckInterface {

  public const CACHE_TAG = 'tannwald_assistant_limit';

  public const CACHE_TTL = 60;

  public const WARN_SHARE = 0.8;

  public const STATE_PREFIX = 'tannwald_assistant.limit_warned.';

  public const ADMIN_ROLE = 'portal_admin';

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LimitMailerInterface $mailer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function allowsAnswer(AccountInterface $account): bool {
    $this->checkWarning();
    return !$this->isSearchOnly();
  }

  /**
   * TRUE ab 100 % des Monatslimits.
   */
  public function isSearchOnly(): bool {
    $limit = $this->limit();
    return $limit > 0 && $this->monthUsage() >= $limit;
  }

  /**
   * Monatslimit in Tokens (0 = unbegrenzt).
   */
  public function limit(): int {
    return max(0, (int) $this->configFactory->get('tannwald_assistant.settings')->get('monthly_token_limit'));
  }

  /**
   * Anteil des Monatsverbrauchs am Limit (0.0 bei unbegrenzt).
   */
  public function share(): float {
    $limit = $this->limit();
    return $limit > 0 ? $this->monthUsage() / $limit : 0.0;
  }

  /**
   * Tokens seit Monatsbeginn (alle Kontexte), 60 s gecacht.
   */
  public function monthUsage(): int {
    $cid = 'tannwald_assistant:month_usage:' . $this->monthKey();
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return (int) $cached->data;
    }
    $usage = (int) $this->database->query(
      'SELECT SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) FROM {tannwald_usage} WHERE created >= :start',
      [':start' => $this->monthStart()],
    )->fetchField();
    $this->cache->set($cid, $usage, $this->time->getRequestTime() + self::CACHE_TTL, [self::CACHE_TAG]);
    return $usage;
  }

  /**
   * Schickt ab 80 % einmal je Monat die Warnmail.
   *
   * @return bool
   *   TRUE, wenn in diesem Aufruf versendet wurde.
   */
  public function checkWarning(): bool {
    $limit = $this->limit();
    if ($limit === 0) {
      return FALSE;
    }
    $usage = $this->monthUsage();
    $key = self::STATE_PREFIX . $this->monthKey();
    if ($usage < self::WARN_SHARE * $limit || $this->state->get($key)) {
      return FALSE;
    }
    // Erst markieren, dann senden: lieber eine Mail zu wenig als eine Flut.
    $this->state->set($key, $this->time->getRequestTime());
    $sent = 0;
    foreach ($this->admins() as $admin) {
      $sent += (int) $this->mailer->warn($admin, $usage, $limit);
    }
    $this->logger->notice('Assistant limit warning: @usage of @limit tokens, @sent mail(s).', [
      '@usage' => $usage,
      '@limit' => $limit,
      '@sent' => $sent,
    ]);
    return TRUE;
  }

  /**
   * Hinweis für die Oberfläche (5.3) oder NULL, solange geantwortet wird.
   */
  public function noticeForUi(): ?string {
    if (!$this->isSearchOnly()) {
      return NULL;
    }
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return (string) new TranslatableMarkup(AnswerTexts::LIMIT_REACHED['en']);
  }

  /**
   * Monat der Request-Zeit, YYYY-MM (Zeitzone der Site).
   */
  public function monthKey(): string {
    return $this->now()->format('Y-m');
  }

  /**
   * Unix-Zeit des Monatsbeginns 00:00 (Zeitzone der Site).
   */
  public function monthStart(): int {
    return $this->now()->modify('first day of this month')->setTime(0, 0)->getTimestamp();
  }

  /**
   * Request-Zeit in der Zeitzone der Site.
   */
  private function now(): \DateTimeImmutable {
    $timezone = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get());
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone(new \DateTimeZone($timezone));
  }

  /**
   * Aktive Konten mit Rolle portal_admin.
   *
   * @return \Drupal\user\UserInterface[]
   *   Empfänger.
   */
  private function admins(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', self::ADMIN_ROLE)
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

}
