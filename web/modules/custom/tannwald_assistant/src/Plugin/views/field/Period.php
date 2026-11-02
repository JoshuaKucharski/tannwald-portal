<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ResultRow;

/**
 * Tag oder Monat einer Unix-Zeit als Gruppierungsspalte (Nutzung, Kosten).
 *
 * Granularität aus der Views-Definition (day|month); die SQL-Formel kommt
 * von Views (getDateField/getDateFormat, datenbankneutral, Site-Zeitzone).
 */
#[ViewsField('tannwald_period')]
final class Period extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query($use_groupby = FALSE): void {
    if (!$this->query instanceof Sql) {
      return;
    }
    $this->ensureMyTable();
    $format = ($this->definition['granularity'] ?? 'day') === 'month' ? 'Y-m' : 'Y-m-d';
    $field = $this->query->getDateField($this->tableAlias . '.' . $this->realField, FALSE, TRUE);
    $this->field_alias = $this->query->addField(NULL, $this->query->getDateFormat($field, $format), $this->tableAlias . '_' . $this->field);
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
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = (string) $this->getValue($values);
    if ($value === '') {
      return '';
    }
    $parts = explode('-', $value);
    $display = count($parts) === 3 ? $parts[2] . '.' . $parts[1] . '.' . $parts[0] : (count($parts) === 2 ? $parts[1] . '.' . $parts[0] : $value);
    return $this->sanitizeValue($display);
  }

}
