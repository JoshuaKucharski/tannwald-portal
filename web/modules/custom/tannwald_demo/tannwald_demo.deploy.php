<?php

/**
 * @file
 * Deploy-Hooks des Seeders (drush deploy, nach config:import).
 */

declare(strict_types=1);

use Drupal\tannwald_demo\DisplayNames;
use Drupal\tannwald_demo\Drush\Commands\DemoCommands;

/**
 * Ob die Nachlieferung der Demo-Konten hier erlaubt ist.
 *
 * DDEV, CI oder TANNWALD_DEMO=1 wie der Seeder; zusätzlich TANNWALD_DEPLOY=1,
 * weil deploy/deploy.sh drush deploy nur damit startet (TANNWALD_DEMO=1 setzt
 * es allein beim Seeder-Aufruf). Ein Konto mit Passwort demo entsteht trotzdem
 * nie aus Versehen: DemoAccounts::started() verlangt, dass der Demo-Satz
 * schon da ist.
 */
function _tannwald_demo_deploy_allowed(): bool {
  return DemoCommands::seedingAllowed() || getenv('TANNWALD_DEPLOY') === '1';
}

/**
 * Liefert fehlende Demo-Konten nach (tw2-12: portal-admin, Fund F11).
 *
 * Idempotent: existiert jedes Konto aus DisplayNames::ACCOUNTS, passiert
 * nichts. Auf einer Site ohne Demo-Konten bleibt der Seeder die Quelle.
 */
function tannwald_demo_deploy_demo_accounts(array &$sandbox): string {
  if (!_tannwald_demo_deploy_allowed()) {
    return (string) t('Demo accounts: not a demo environment, nothing changed.');
  }
  /** @var \Drupal\tannwald_demo\DemoAccounts $accounts */
  $accounts = \Drupal::service('tannwald_demo.accounts');
  if (!$accounts->started()) {
    return (string) t('Demo accounts: none seeded yet, the seeder (tannwald:demo-install) is the source; nothing changed.');
  }
  $created = $accounts->ensure();
  if ($created === []) {
    return (string) t('Demo accounts: all @count present, nothing changed.', ['@count' => count(DisplayNames::names())]);
  }
  return (string) t('Demo accounts: created @names (password demo).', ['@names' => implode(', ', $created)]);
}
