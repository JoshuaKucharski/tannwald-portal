<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant_test;

use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Liefert für jeden Provider den FakeChatProvider.
 */
final class FakeChatManager implements FactoryInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): FakeChatProvider {
    return new FakeChatProvider($this->state);
  }

}
