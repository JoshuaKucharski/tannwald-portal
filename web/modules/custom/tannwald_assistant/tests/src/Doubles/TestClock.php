<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Doubles;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Verstellbare Uhr für Monatswechsel in Tests.
 */
final class TestClock implements TimeInterface {

  public function __construct(
    public int $now,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return (float) $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return (float) $this->now;
  }

}
