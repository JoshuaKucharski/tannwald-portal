<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\tannwald_demo\DemoInstaller;
use Drupal\tannwald_demo\DisplayNames;
use Drupal\tannwald_demo\FixturePath;

/**
 * Hooks des Seeders.
 */
final class DemoHooks {

  /**
   * Implements hook_migration_plugins_alter().
   *
   * Relative Quell-URLs der Demo-Migrationen zeigen auf fixtures/ im
   * Repo-Stamm (DRUPAL_ROOT/../fixtures).
   */
  #[Hook('migration_plugins_alter')]
  public function migrationPluginsAlter(array &$migrations): void {
    foreach (DemoInstaller::MIGRATIONS as $id) {
      if (!isset($migrations[$id]['source']['urls'])) {
        continue;
      }
      $migrations[$id]['source']['urls'] = array_map(
        [FixturePath::class, 'resolve'],
        (array) $migrations[$id]['source']['urls'],
      );
    }
  }

  /**
   * Implements hook_user_format_name_alter().
   */
  #[Hook('user_format_name_alter')]
  public function userFormatNameAlter(string &$name, AccountInterface $account): void {
    $display = DisplayNames::of((string) $account->getAccountName());
    if ($display !== NULL) {
      $name = $display;
    }
  }

}
