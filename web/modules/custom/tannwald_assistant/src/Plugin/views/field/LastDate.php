<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ResultRow;

/**
 * Jüngste Unix-Zeit einer Gruppe als TT.MM.JJJJ.
 *
 * Eigenes MAX(), weil Views bei group_type max den Zahlen-Handler einsetzt.
 */
#[ViewsField('tannwald_last_date')]
final class LastDate extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query($use_groupby = FALSE): void {
    if (!$this->query instanceof Sql) {
      return;
    }
    $this->ensureMyTable();
    $this->field_alias = $this->query->addField($this->tableAlias, $this->realField, $this->tableAlias . '_' . $this->field, ['function' => 'max']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    return $value === NULL || $value === '' ? '' : $this->sanitizeValue(date('d.m.Y', (int) $value));
  }

}
