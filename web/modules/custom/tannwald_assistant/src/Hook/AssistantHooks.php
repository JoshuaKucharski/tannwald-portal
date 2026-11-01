<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hooks von tannwald_assistant: Theme und Views-Integration.
 */
final class AssistantHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'tannwald_search_page' => [
        'variables' => [
          'query' => '',
          'modus' => 'hybrid',
          'aktuell' => FALSE,
          'filters' => [],
          'hits' => [],
          'facets' => [],
          'total' => 0,
          'dauer_ms' => 0,
          'pager' => NULL,
          'live_url' => '',
        ],
      ],
    ];
  }

  /**
   * Implements hook_views_data().
   *
   * Tannwald_assistant_log (eigene Tabelle) und tannwald_usage (Tabelle von
   * tannwald_ingest; die Views-Integration liefert der Konsument, Entscheid
   * des Masters 4.2).
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data = [];
    $log = &$data['tannwald_assistant_log'];
    $log['table'] = [
      'group' => $this->t('Assistant log'),
      'base' => [
        'field' => 'id',
        'title' => $this->t('Assistant log'),
        'help' => $this->t('One row per assistant answer (pseudonymised).'),
      ],
    ];
    $log['id'] = self::numeric($this->t('Log ID'));
    $log['frage'] = self::text($this->t('Question'));
    $log['frage_normalisiert'] = self::text($this->t('Question (normalised)'));
    $log['frage_normalisiert']['argument'] = ['id' => 'string'];
    $log['status'] = self::text($this->t('Status'));
    $log['langcode'] = self::text($this->t('Language'));
    $log['bewertung'] = self::numeric($this->t('Rating'));
    $log['dauer_ms'] = self::numeric($this->t('Duration (ms)'));
    $log['injection_verdacht'] = self::numeric($this->t('Injection suspicion'));
    $log['maschine_nid'] = self::numeric($this->t('Machine ID'));
    $log['maschine_nid']['relationship'] = [
      'title' => $this->t('Machine'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'id' => 'standard',
      'label' => $this->t('Machine'),
    ];
    $log['created'] = self::date($this->t('Created'));
    $log['current_month'] = [
      'title' => $this->t('Current month'),
      'help' => $this->t('Rows created since the start of the current month (site time zone).'),
      'real field' => 'created',
      'filter' => ['id' => 'tannwald_current_month'],
    ];
    $log['last_created'] = [
      'title' => $this->t('Last asked'),
      'real field' => 'created',
      'field' => ['id' => 'tannwald_last_date'],
    ];

    $usage = &$data['tannwald_usage'];
    $usage['table'] = [
      'group' => $this->t('Assistant usage'),
      'base' => [
        'field' => 'id',
        'title' => $this->t('Assistant usage'),
        'help' => $this->t('Token usage per AI call (table of tannwald_ingest).'),
      ],
    ];
    $usage['id'] = self::numeric($this->t('Usage ID'));
    $usage['modell'] = self::text($this->t('Model'));
    $usage['operation'] = self::text($this->t('Operation'));
    $usage['kontext'] = self::text($this->t('Context'));
    $usage['prompt_tokens'] = self::numeric($this->t('Prompt tokens'));
    $usage['completion_tokens'] = self::numeric($this->t('Completion tokens'));
    $usage['kosten_chf'] = self::numeric($this->t('Cost (CHF)'));
    $usage['created'] = self::date($this->t('Created'));
    $usage['period_day'] = [
      'title' => $this->t('Day'),
      'real field' => 'created',
      'field' => ['id' => 'tannwald_period', 'granularity' => 'day', 'click sortable' => TRUE],
    ];
    $usage['period_month'] = [
      'title' => $this->t('Month'),
      'real field' => 'created',
      'field' => ['id' => 'tannwald_period', 'granularity' => 'month', 'click sortable' => TRUE],
    ];
    $usage['tokens_total'] = [
      'title' => $this->t('Tokens (total)'),
      'real field' => 'prompt_tokens',
      'field' => ['id' => 'tannwald_tokens_total'],
    ];
    $usage['limit_share'] = [
      'title' => $this->t('Share of monthly limit'),
      'real field' => 'prompt_tokens',
      'field' => ['id' => 'tannwald_limit_share'],
    ];

    $data['views']['tannwald_answer_stats'] = [
      'title' => $this->t('Assistant answers per day'),
      'help' => $this->t('Answers and average duration per day from the assistant log.'),
      'area' => ['id' => 'tannwald_answer_stats'],
    ];
    return $data;
  }

  /**
   * Views-Definition einer Textspalte.
   */
  private static function text(\Stringable|string $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'standard', 'click sortable' => TRUE],
      'filter' => ['id' => 'string'],
      'sort' => ['id' => 'standard'],
    ];
  }

  /**
   * Views-Definition einer Zahlenspalte.
   */
  private static function numeric(\Stringable|string $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'numeric', 'click sortable' => TRUE],
      'filter' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'numeric'],
    ];
  }

  /**
   * Views-Definition einer Unix-Zeit-Spalte.
   */
  private static function date(\Stringable|string $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'date', 'click sortable' => TRUE],
      'filter' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
    ];
  }

}
