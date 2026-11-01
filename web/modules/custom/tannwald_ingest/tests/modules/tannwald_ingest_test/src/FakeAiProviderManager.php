<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest_test;

use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Liefert immer den FakeAiProvider.
 */
final class FakeAiProviderManager implements PluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): FakeAiProvider {
    return new FakeAiProvider();
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options): FakeAiProvider {
    return new FakeAiProvider();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE): array {
    return ['id' => $plugin_id];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return ['ollama' => ['id' => 'ollama']];
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id): bool {
    return TRUE;
  }

}
