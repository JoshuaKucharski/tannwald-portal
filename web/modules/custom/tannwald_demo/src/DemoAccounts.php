<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\user\UserInterface;

/**
 * Nachlieferung fehlender Demo-Konten (tw2-12, Fund F11 von E).
 *
 * Der Seeder (Migration tannwald_users) legt die Konten bei einer frischen
 * Installation an; auf dem Demo-Server läuft er nur beim ersten Install. Ein
 * später hinzugekommenes Konto (portal-admin) liefert tannwald_demo.deploy.php
 * über diese Klasse nach: fehlt ein Konto aus DisplayNames::ACCOUNTS, entsteht
 * es wie im Seeder aus fixtures/sap/nutzer.json (Name, Mail, Rolle, Firma,
 * Land, Sprache, Passwort demo); vorhandene Konten bleiben unberührt.
 */
final class DemoAccounts {

  /**
   * Passwort aller Demo-Konten (kein Geheimnis, Pflichtenheft).
   */
  public const PASSWORD = 'demo';

  /**
   * Mail-Domäne der Demo-Konten (wie die Migration tannwald_users).
   */
  public const MAIL_DOMAIN = '@tannwald.example';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DemoLookup $lookup,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Namen der Demo-Konten, die in der Datenbank fehlen.
   *
   * @return string[]
   *   Kontonamen in Seeder-Reihenfolge.
   */
  public function missing(): array {
    $existing = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', DisplayNames::names(), 'IN')
      ->execute();
    $names = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($existing) as $account) {
      $names[] = $account->getAccountName();
    }
    return array_values(array_diff(DisplayNames::names(), $names));
  }

  /**
   * Ob der Demo-Satz schon begonnen wurde (mindestens ein Demo-Konto da).
   *
   * Die Nachlieferung ergänzt nur einen vorhandenen Satz; auf einer Site ohne
   * Demo-Konten bleibt der Seeder die einzige Quelle (kein Konto mit Passwort
   * demo aus Versehen).
   */
  public function started(): bool {
    return count($this->missing()) < count(DisplayNames::names());
  }

  /**
   * Legt fehlende Demo-Konten aus der Fixture an.
   *
   * @return string[]
   *   Namen der angelegten Konten (leer, wenn nichts fehlte).
   */
  public function ensure(): array {
    $missing = $this->missing();
    if ($missing === []) {
      return [];
    }
    $created = [];
    foreach ($this->fixtureRows() as $row) {
      $name = (string) ($row['name'] ?? '');
      if (!in_array($name, $missing, TRUE)) {
        continue;
      }
      $this->create($row);
      $created[] = $name;
    }
    return $created;
  }

  /**
   * Zeilen aus fixtures/sap/nutzer.json.
   *
   * @return array<int, array<string, string>>
   *   Zeilen der Fixture.
   */
  protected function fixtureRows(): array {
    $path = FixturePath::resolve('sap/nutzer.json');
    if (!is_file($path)) {
      return [];
    }
    $rows = json_decode((string) file_get_contents($path), TRUE);
    return is_array($rows) ? $rows : [];
  }

  /**
   * Legt ein Konto wie die Migration tannwald_users an.
   *
   * @param array<string, string> $row
   *   Fixture-Zeile (name, rolle, firma_sap_nr, land, sprache).
   */
  protected function create(array $row): UserInterface {
    $name = (string) $row['name'];
    // Sprache nur, wenn sie eingerichtet ist (sonst gingen Feldwerte wie die
    // Rollen unter einer unbekannten Sprache verloren); sonst Standardsprache.
    $langcode = (string) ($row['sprache'] ?? '');
    if ($langcode === '' || $this->languageManager->getLanguage($langcode) === NULL) {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    $values = [
      'name' => $name,
      'mail' => $name . self::MAIL_DOMAIN,
      'init' => $name . self::MAIL_DOMAIN,
      'pass' => self::PASSWORD,
      'status' => 1,
      'langcode' => $langcode,
      'preferred_langcode' => $langcode,
    ];
    $role = (string) ($row['rolle'] ?? '');
    if ($role !== '' && $this->entityTypeManager->getStorage('user_role')->load($role) !== NULL) {
      $values['roles'] = [$role];
    }
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->create($values);
    if ($account->hasField('field_land') && !empty($row['land'])) {
      $account->set('field_land', (string) $row['land']);
    }
    if ($account->hasField('field_firma') && !empty($row['firma_sap_nr'])) {
      $tid = $this->lookup->termId('firma', (string) $row['firma_sap_nr']);
      if ($tid !== NULL) {
        $account->set('field_firma', $tid);
      }
    }
    $account->save();
    return $account;
  }

}
