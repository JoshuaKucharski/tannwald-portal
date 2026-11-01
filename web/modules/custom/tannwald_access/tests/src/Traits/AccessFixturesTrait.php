<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Baut das Content-Modell nach Vertrag RunBook §1.1 und eine Testwelt.
 *
 * Die Namen sind exakt die Vertragsnamen, damit dieselben Tests später gegen
 * A's Konfiguration (2.1) identisch laufen. Die Welt:
 * - Firmen: A (Kunde, CH, Maschine TB-200), B (Kunde, BR, Maschine KT-500),
 *   C (Kunde, AR, Maschine TB-200), H (Händler, BR, Gebiet [BR, AR]).
 *   C existiert, damit der Händler TB-200 im Gebiet hat und nur die
 *   Exportbedingung den Schaltplan sperrt (Beweis der Gegenprobe).
 * - Exportregeln: klasse_2 = CH, DE, AT, FR, IT, NL, US, JP; klasse_1 =
 *   dieselbe Liste plus BR (Händlerland, wie der Seeder 2.3).
 */
trait AccessFixturesTrait {

  /**
   * Erlaubte Länder der Klasse 2 laut config/install.
   */
  protected const KLASSE_2_COUNTRIES = ['CH', 'DE', 'AT', 'FR', 'IT', 'NL', 'US', 'JP'];

  /**
   * Die sechs Rollen des Vertrags.
   */
  protected const CONTRACT_ROLES = ['kunde', 'haendler', 'techniker', 'redaktion', 'compliance', 'portal_admin'];

  /**
   * Legt Bundles, Vokabulare, Felder und Rollen an.
   */
  protected function createContentModel(): void {
    foreach (['dokument', 'bulletin', 'maschine', 'zusammenfassung'] as $type) {
      NodeType::create(['type' => $type, 'name' => ucfirst($type)])->save();
    }
    foreach (['maschinentyp', 'firma'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => ucfirst($vid)])->save();
    }
    $this->addField('taxonomy_term', 'maschinentyp', 'field_typ_code', 'string');
    $this->addField('taxonomy_term', 'firma', 'field_sap_nr', 'string');
    $this->addField('taxonomy_term', 'firma', 'field_land', 'string');
    $this->addField('taxonomy_term', 'firma', 'field_firma_art', 'list_string', 1, [
      'allowed_values' => ['kunde' => 'Kunde', 'haendler' => 'Händler'],
    ]);
    $this->addField('taxonomy_term', 'firma', 'field_haendler_gebiet', 'string', -1);
    $this->addField('user', 'user', 'field_firma', 'entity_reference', 1, ['target_type' => 'taxonomy_term'], [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['firma' => 'firma']],
    ]);
    $this->addField('user', 'user', 'field_land', 'string');

    $termReference = [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['maschinentyp' => 'maschinentyp']],
    ];
    foreach (['dokument', 'bulletin', 'maschine'] as $bundle) {
      $this->addField('node', $bundle, 'field_maschinentyp', 'entity_reference', -1, ['target_type' => 'taxonomy_term'], $termReference);
    }
    $this->addField('node', 'maschine', 'field_firma', 'entity_reference', 1, ['target_type' => 'taxonomy_term'], [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['firma' => 'firma']],
    ]);
    $machineReference = [
      'handler' => 'default:node',
      'handler_settings' => ['target_bundles' => ['maschine' => 'maschine']],
    ];
    $this->addField('node', 'dokument', 'field_seriennummer', 'entity_reference', 1, ['target_type' => 'node'], $machineReference);
    $this->addField('node', 'bulletin', 'field_seriennummern', 'entity_reference', -1, ['target_type' => 'node'], $machineReference);
    $this->addField('node', 'dokument', 'field_exportklasse', 'list_string', 1, [
      'allowed_values' => ['keine' => 'Keine', 'klasse_1' => 'Klasse 1', 'klasse_2' => 'Klasse 2'],
    ]);
    $this->addField('node', 'dokument', 'field_version', 'string');
    $this->addField('node', 'dokument', 'field_datei', 'file', 1, ['uri_scheme' => 'private'], [
      'file_directory' => 'dokumente',
      'file_extensions' => 'pdf txt html',
    ]);
    $this->addField('node', 'bulletin', 'field_bulletin_art', 'list_string', 1, [
      'allowed_values' => ['service' => 'Service', 'sicherheit' => 'Sicherheit'],
    ]);
    $this->addField('node', 'zusammenfassung', 'field_dokument', 'entity_reference', 1, ['target_type' => 'node'], [
      'handler' => 'default:node',
      'handler_settings' => ['target_bundles' => ['dokument' => 'dokument']],
    ]);

    // Grundrechte wie A (2.1): Inhalte ansehen; Entwürfe nur Redaktion,
    // Compliance, Portal-Admin.
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $rid) {
      $role = Role::load($rid) ?? Role::create(['id' => $rid, 'label' => $rid]);
      $role->grantPermission('access content')->save();
    }
    foreach (self::CONTRACT_ROLES as $rid) {
      $role = Role::create(['id' => $rid, 'label' => $rid]);
      if (in_array($rid, ['redaktion', 'compliance', 'portal_admin'], TRUE)) {
        $role->grantPermission('view any unpublished content');
      }
      $role->save();
    }
  }

  /**
   * Legt ein Feld an (Storage, falls nötig, und Instanz).
   *
   * @param string $entityType
   *   Entity-Typ.
   * @param string $bundle
   *   Bundle.
   * @param string $name
   *   Feldname.
   * @param string $type
   *   Feldtyp.
   * @param int $cardinality
   *   Kardinalität.
   * @param array<string, mixed> $storageSettings
   *   Storage-Einstellungen.
   * @param array<string, mixed> $fieldSettings
   *   Feld-Einstellungen.
   */
  protected function addField(string $entityType, string $bundle, string $name, string $type, int $cardinality = 1, array $storageSettings = [], array $fieldSettings = []): void {
    if (FieldStorageConfig::loadByName($entityType, $name) === NULL) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $name,
      'settings' => $fieldSettings,
    ])->save();
  }

  /**
   * Setzt die Exportregeln.
   *
   * @param string[] $klasse1
   *   Länder Klasse 1.
   * @param string[] $klasse2
   *   Länder Klasse 2.
   * @param string[] $blocked2
   *   Gesperrte SAP-Nummern Klasse 2.
   */
  protected function setExportRules(array $klasse1, array $klasse2, array $blocked2 = []): void {
    $this->config('tannwald_access.settings')->set('export_rules', [
      'klasse_1' => ['laender' => $klasse1, 'gesperrte_firmen' => []],
      'klasse_2' => ['laender' => $klasse2, 'gesperrte_firmen' => $blocked2],
    ])->save();
  }

  /**
   * Baut die Testwelt (Terme, Firmen, Maschinen, Dokumente, Exportregeln).
   *
   * @return array<string, \Drupal\Core\Entity\EntityInterface>
   *   Entities nach Schlüssel.
   */
  protected function createAccessWorld(): array {
    $this->setExportRules(array_merge(self::KLASSE_2_COUNTRIES, ['BR']), self::KLASSE_2_COUNTRIES);
    $w = [];
    $w['tb200'] = $this->createAccessTerm('maschinentyp', 'TB-200', ['field_typ_code' => 'TB200']);
    $w['tb400'] = $this->createAccessTerm('maschinentyp', 'TB-400', ['field_typ_code' => 'TB400']);
    $w['kt500'] = $this->createAccessTerm('maschinentyp', 'KT-500', ['field_typ_code' => 'KT500']);
    $w['firma_a'] = $this->createAccessTerm('firma', 'Kunde A', [
      'field_sap_nr' => '1001',
      'field_land' => 'CH',
      'field_firma_art' => 'kunde',
    ]);
    $w['firma_b'] = $this->createAccessTerm('firma', 'Kunde B', [
      'field_sap_nr' => '1002',
      'field_land' => 'BR',
      'field_firma_art' => 'kunde',
    ]);
    $w['firma_c'] = $this->createAccessTerm('firma', 'Kunde C', [
      'field_sap_nr' => '1003',
      'field_land' => 'AR',
      'field_firma_art' => 'kunde',
    ]);
    $w['firma_h'] = $this->createAccessTerm('firma', 'Haendler H', [
      'field_sap_nr' => '2001',
      'field_land' => 'BR',
      'field_firma_art' => 'haendler',
      'field_haendler_gebiet' => ['BR', 'AR'],
    ]);
    $w['m1'] = $this->createAccessNode('maschine', 'TB200-2021-0417', [
      'field_maschinentyp' => $w['tb200'],
      'field_firma' => $w['firma_a'],
    ]);
    $w['m2'] = $this->createAccessNode('maschine', 'KT500-2022-0001', [
      'field_maschinentyp' => $w['kt500'],
      'field_firma' => $w['firma_b'],
    ]);
    $w['m3'] = $this->createAccessNode('maschine', 'TB200-2023-0099', [
      'field_maschinentyp' => $w['tb200'],
      'field_firma' => $w['firma_c'],
    ]);

    $w['d_ba'] = $this->createAccessNode('dokument', 'Betriebsanleitung TB-200', [
      'field_maschinentyp' => [$w['tb200']],
      'field_exportklasse' => 'keine',
    ]);
    $w['d_hf'] = $this->createAccessNode('dokument', 'Schaltplan Hochfrequenz-Siegelung', [
      'field_maschinentyp' => [$w['tb200']],
      'field_exportklasse' => 'klasse_2',
    ]);
    $w['d_anlage'] = $this->createAccessNode('dokument', 'Anlagendokumentation TB200-2021-0417', [
      'field_maschinentyp' => [$w['tb200']],
      'field_seriennummer' => $w['m1'],
      'field_exportklasse' => 'keine',
    ]);
    $w['d_mehrtyp'] = $this->createAccessNode('dokument', 'Schulungsunterlage TB-200 und TB-400', [
      'field_maschinentyp' => [$w['tb200'], $w['tb400']],
      'field_exportklasse' => 'klasse_1',
    ]);
    $w['b_sb'] = $this->createAccessNode('bulletin', 'SB-2026-07', [
      'field_maschinentyp' => [$w['tb200']],
      'field_seriennummern' => [$w['m1']],
      'field_bulletin_art' => 'sicherheit',
    ]);
    $w['d_kt'] = $this->createAccessNode('dokument', 'Wartungsplan KT-500', [
      'field_maschinentyp' => [$w['kt500']],
      'field_exportklasse' => 'keine',
    ]);
    $w['d_entwurf'] = $this->createAccessNode('dokument', 'Entwurf Betriebsanleitung TB-200', [
      'field_maschinentyp' => [$w['tb200']],
      'field_exportklasse' => 'keine',
    ], FALSE);
    return $w;
  }

  /**
   * Legt einen Term an.
   *
   * @param string $vid
   *   Vokabular.
   * @param string $name
   *   Name.
   * @param array<string, mixed> $values
   *   Feldwerte.
   */
  protected function createAccessTerm(string $vid, string $name, array $values = []): TermInterface {
    $term = Term::create(['vid' => $vid, 'name' => $name] + $values);
    $term->save();
    return $term;
  }

  /**
   * Legt einen Node an.
   *
   * @param string $type
   *   Bundle.
   * @param string $title
   *   Titel.
   * @param array<string, mixed> $values
   *   Feldwerte.
   * @param bool $published
   *   Veröffentlicht.
   */
  protected function createAccessNode(string $type, string $title, array $values = [], bool $published = TRUE): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => (int) $published] + $values);
    $node->save();
    return $node;
  }

  /**
   * ID einer Entity der Welt als String.
   */
  protected static function idOf(EntityInterface $entity): string {
    return (string) $entity->id();
  }

}
