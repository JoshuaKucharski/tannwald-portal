<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_demo\Unit;

use Drupal\tannwald_demo\Drush\Commands\DemoCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Wächter des Seeders: DDEV, CI oder ausdrücklich TANNWALD_DEMO=1 (4.1).
 */
#[Group('tannwald_demo')]
final class SeedingAllowedTest extends TestCase {

  private const NAMES = ['IS_DDEV_PROJECT', 'CI', 'TANNWALD_DEMO', 'TANNWALD_DEPLOY'];

  /**
   * Umgebung vor dem Test (DDEV setzt IS_DDEV_PROJECT im Container).
   *
   * @var array<string, string|false>
   */
  private array $saved = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (self::NAMES as $name) {
      $this->saved[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->saved as $name => $value) {
      putenv($value === FALSE ? $name : "$name=$value");
    }
    parent::tearDown();
  }

  /**
   * Fälle: gesetzte Variablen → erwartetes Ergebnis.
   *
   * @return array<string, array{array<string, string>, bool}>
   *   Name → [Umgebung, erlaubt].
   */
  public static function cases(): array {
    return [
      'nichts gesetzt' => [[], FALSE],
      'Server ohne Opt-in (TANNWALD_DEPLOY=1)' => [['TANNWALD_DEPLOY' => '1'], FALSE],
      'Opt-in TANNWALD_DEMO=0' => [['TANNWALD_DEMO' => '0', 'TANNWALD_DEPLOY' => '1'], FALSE],
      'Opt-in TANNWALD_DEMO=yes' => [['TANNWALD_DEMO' => 'yes'], FALSE],
      'Opt-in TANNWALD_DEMO=1' => [['TANNWALD_DEMO' => '1', 'TANNWALD_DEPLOY' => '1'], TRUE],
      'DDEV' => [['IS_DDEV_PROJECT' => 'true'], TRUE],
      'CI' => [['CI' => 'true'], TRUE],
    ];
  }

  /**
   * Prüft DemoCommands::seedingAllowed() für jeden Fall.
   *
   * @param array<string, string> $env
   *   Zu setzende Variablen.
   * @param bool $expected
   *   Erwartetes Ergebnis.
   */
  #[DataProvider('cases')]
  public function testSeedingAllowed(array $env, bool $expected): void {
    foreach ($env as $name => $value) {
      putenv("$name=$value");
    }
    $this->assertSame($expected, DemoCommands::seedingAllowed());
  }

}
