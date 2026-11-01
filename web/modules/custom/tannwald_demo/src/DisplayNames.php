<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

/**
 * Anzeigenamen der sechs Demo-Konten (Demo-Hilfe ohne eigenes Nutzerfeld).
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
  ];

  /**
   * Anzeigename eines Kontos oder NULL, wenn es kein Demo-Konto ist.
   */
  public static function of(string $accountName): ?string {
    return self::ACCOUNTS[$accountName] ?? NULL;
  }

}
