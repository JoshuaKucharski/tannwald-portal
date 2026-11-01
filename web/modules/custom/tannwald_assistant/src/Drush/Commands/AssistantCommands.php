<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\tannwald_assistant\AnswerService;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush-Befehle des Assistenten (Löschjob, Pflichtenheft §9 Datenschutz).
 */
final class AssistantCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Löscht Assistent-Log-Zeilen älter als die Spanne.
   */
  #[CLI\Command(name: 'tannwald:log-purge')]
  #[CLI\Option(name: 'older-than', description: 'Age as <n>m (months) or <n>d (days), default 12m.')]
  #[CLI\Option(name: 'dry', description: 'Only count, delete nothing.')]
  #[CLI\Usage(name: 'drush tannwald:log-purge --older-than=12m', description: 'Delete log rows older than twelve months.')]
  public function purge(array $options = ['older-than' => '12m', 'dry' => FALSE]): int {
    $cutoff = self::cutoff((string) $options['older-than'], $this->time->getRequestTime());
    $count = (int) $this->database->select(AnswerService::LOG_TABLE, 'l')
      ->condition('created', $cutoff, '<')
      ->countQuery()
      ->execute()
      ->fetchField();
    if (!empty($options['dry'])) {
      $this->io()->writeln(sprintf('%d rows older than %s would be deleted (dry run).', $count, date('Y-m-d H:i', $cutoff)));
      return self::EXIT_SUCCESS;
    }
    $deleted = $this->database->delete(AnswerService::LOG_TABLE)
      ->condition('created', $cutoff, '<')
      ->execute();
    $this->io()->writeln(sprintf('%d rows older than %s deleted.', $deleted, date('Y-m-d H:i', $cutoff)));
    return self::EXIT_SUCCESS;
  }

  /**
   * Stichtag aus einer Spanne „<n>m“ oder „<n>d“.
   *
   * @throws \InvalidArgumentException
   *   Bei anderem Format.
   */
  public static function cutoff(string $span, int $now): int {
    if (!preg_match('/^(\d{1,3})([md])$/', trim($span), $m) || (int) $m[1] === 0) {
      throw new \InvalidArgumentException(sprintf('Invalid --older-than "%s": expected <n>m or <n>d, e.g. 12m or 30d.', $span));
    }
    $unit = $m[2] === 'm' ? 'months' : 'days';
    return (int) (new \DateTimeImmutable('@' . $now))->modify(sprintf('-%d %s', (int) $m[1], $unit))->getTimestamp();
  }

}
