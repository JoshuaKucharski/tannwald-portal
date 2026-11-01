<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Plugin\views\area;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\tannwald_assistant\AnswerService;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Antworten je Tag mit Durchschnittsdauer aus dem Assistent-Log.
 *
 * Durchschnitt statt Median: Views kann keinen Median aggregieren (4.2).
 */
#[ViewsArea('tannwald_answer_stats')]
final class AnswerStats extends AreaPluginBase {

  public const DAYS = 30;

  /**
   * Datenbank.
   */
  protected Connection $database;

  /**
   * Zeit.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->database = $container->get('database');
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    $rows = $this->database->select(AnswerService::LOG_TABLE, 'l')
      ->fields('l', ['created', 'dauer_ms', 'status'])
      ->condition('created', $this->time->getRequestTime() - self::DAYS * 86400, '>=')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    $days = [];
    foreach ($rows as $row) {
      $day = date('Y-m-d', (int) $row['created']);
      $days[$day] ??= ['count' => 0, 'ms' => 0, 'abgelehnt' => 0];
      $days[$day]['count']++;
      $days[$day]['ms'] += (int) $row['dauer_ms'];
      $days[$day]['abgelehnt'] += (int) ($row['status'] === 'abgelehnt');
    }
    krsort($days);
    $table = [];
    foreach ($days as $day => $stats) {
      $table[] = [
        date('d.m.Y', (int) strtotime($day)),
        $stats['count'],
        $stats['abgelehnt'],
        number_format($stats['ms'] / max(1, $stats['count']), 0, '.', '’'),
      ];
    }
    return [
      '#type' => 'table',
      '#caption' => $this->t('Assistant answers per day (last @days days)', ['@days' => self::DAYS]),
      '#header' => [$this->t('Day'), $this->t('Answers'), $this->t('Refused'), $this->t('Average duration (ms)')],
      '#rows' => $table,
      '#empty' => $this->t('No answers yet.'),
      '#cache' => ['max-age' => 0],
    ];
  }

}
