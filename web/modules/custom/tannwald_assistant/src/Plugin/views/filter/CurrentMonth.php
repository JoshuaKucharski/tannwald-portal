<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Plugin\views\filter;

use Drupal\tannwald_assistant\Limit\LimitService;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Nur Zeilen seit Monatsbeginn (Zeitzone der Site, wie das Monatslimit).
 *
 * Eigenes Plugin, weil der Views-Datumsfilter „offset“ strtotime() relativ
 * zur Epoche rechnet: „first day of this month“ wird dort zu jetzt − 1 h.
 */
#[ViewsFilter('tannwald_current_month')]
final class CurrentMonth extends FilterPluginBase {

  /**
   * Liefert den Monatsbeginn.
   */
  protected LimitService $limitService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->limitService = $container->get('tannwald_assistant.limit');
    // Kein Operator-Formular (Eigenschaft von FilterPluginBase).
    $instance->no_operator = TRUE;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function canExpose(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): string {
    return (string) $this->t('current month');
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    if (!$this->query instanceof Sql) {
      return;
    }
    $this->ensureMyTable();
    $this->query->addWhere($this->options['group'], $this->tableAlias . '.' . $this->realField, (string) $this->limitService->monthStart(), '>=');
  }

}
