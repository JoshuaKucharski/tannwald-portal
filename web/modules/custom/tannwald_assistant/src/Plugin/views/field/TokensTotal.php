<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ResultRow;

/**
 * Summe prompt_tokens + completion_tokens je Gruppe (tannwald_usage).
 */
#[ViewsField('tannwald_tokens_total')]
class TokensTotal extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query($use_groupby = FALSE): void {
    if (!$this->query instanceof Sql) {
      return;
    }
    $this->ensureMyTable();
    $formula = 'COALESCE(' . $this->tableAlias . '.prompt_tokens, 0) + COALESCE(' . $this->tableAlias . '.completion_tokens, 0)';
    $this->field_alias = $this->query->addField(NULL, $formula, $this->tableAlias . '_' . $this->field, ['function' => 'sum']);
  }

  /**
   * {@inheritdoc}
   */
  public function clickSort($order): void {
    if ($this->query instanceof Sql) {
      $this->query->addOrderBy(NULL, NULL, $order, $this->field_alias);
    }
  }

  /**
   * Summe der Gruppe als Ganzzahl.
   */
  protected function total(ResultRow $values): int {
    return (int) $this->getValue($values);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    return $this->sanitizeValue(number_format($this->total($values), 0, '.', '’'));
  }

}
