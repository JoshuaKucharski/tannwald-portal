<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

/**
 * Anzeigenamen der sieben Demo-Konten (Demo-Hilfe ohne eigenes Nutzerfeld).
 *
 * ACCOUNTS ist die eine Namensliste der Demo-Konten: Seeder (Fixture
 * sap/nutzer.json, DemoInstallTest prüft die Gleichheit), Nachlieferung
 * (tannwald_demo.deploy.php, DemoAccounts), Sperre des Nutzerformulars
 * (DemoHooks) und die Wechsel-Links des Themes lesen sie.
 *
 * Das Datenmodell hat kein Feld für den Anzeigenamen; ein neues Feld wäre ein
 * Schema-Entscheid des Betreibers. Die Tabelle spiegelt "anzeigename" aus
 * fixtures/sap/nutzer.json (DemoInstallTest prüft die Gleichheit) und wirkt
 * über hook_user_format_name_alter() nur auf diese Konten.
 */
final class DisplayNames {

  /**
   * Konto => Anzeigename.
   */
  public const ACCOUNTS = [
    'kunde-a' => 'Anna Keller',
    'kunde-b' => 'Elena Vargas',
    'haendler' => 'Rafael Monteiro',
    'techniker' => 'Marco Steiner',
    'redaktion' => 'Lea Brunner',
    'compliance' => 'Nadine Frei',
    'portal-admin' => 'Jonas Widmer',
  ];

  /**
   * Kontonamen der Demo-Konten in Seeder-Reihenfolge.
   *
   * @return string[]
   *   Kontonamen.
   */
  public static function names(): array {
    return array_keys(self::ACCOUNTS);
  }

  /**
   * Ob ein Kontoname zu den Demo-Konten gehört.
   */
  public static function isDemoAccount(string $accountName): bool {
    return isset(self::ACCOUNTS[$accountName]);
  }

  /**
   * Anzeigename eines Kontos oder NULL, wenn es kein Demo-Konto ist.
   */
  public static function of(string $accountName): ?string {
    return self::ACCOUNTS[$accountName] ?? NULL;
  }

}
