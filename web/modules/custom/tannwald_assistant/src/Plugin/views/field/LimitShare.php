<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Anteil der Tokens einer Gruppe am Monatslimit (Prozent).
 *
 * Bezugsgrösse ist das aktuelle monthly_token_limit (frühere Limits werden
 * nicht historisiert); 0 = unbegrenzt → „–“.
 */
#[ViewsField('tannwald_limit_share')]
final class LimitShare extends TokensTotal {

  /**
   * Konfiguration.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $limit = (int) $this->configFactory->get('tannwald_assistant.settings')->get('monthly_token_limit');
    if ($limit <= 0) {
      return '–';
    }
    return $this->sanitizeValue(number_format($this->total($values) / $limit * 100, 1, '.', '’') . ' %');
  }

}
