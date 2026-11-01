<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tannwald_assistant\Drush\Commands\AssistantCommands;
use PHPUnit\Framework\Attributes\Group;

/**
 * Drush tannwald:log-purge: Format der Spanne (12m, 30d).
 */
#[Group('tannwald_assistant')]
final class LogPurgeCutoffTest extends UnitTestCase {

  /**
   * Monate und Tage; ungültige Formate werfen.
   */
  public function testCutoff(): void {
    $now = (int) (new \DateTimeImmutable('2026-11-01 12:00:00 UTC'))->getTimestamp();
    $this->assertSame((int) (new \DateTimeImmutable('2025-11-01 12:00:00 UTC'))->getTimestamp(), AssistantCommands::cutoff('12m', $now));
    $this->assertSame($now - 30 * 86400, AssistantCommands::cutoff('30d', $now));
    foreach (['12', '0m', '12y', '-1d', ''] as $invalid) {
      try {
        AssistantCommands::cutoff($invalid, $now);
        $this->fail('Expected exception for ' . $invalid);
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
