<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wächter für das Content-Modell (Aufgabe 2.1, RunBook §1.1).
 *
 * Liest die exportierte Konfiguration in config/sync, ohne Drupal zu booten:
 * Bundles mit Feldern (Name, Typ, Kardinalität, Pflicht, Ziel, Listenwerte),
 * Vokabulare, Rollen, Workflow redaktion, Textformat dokument_html, View
 * portal_maschinen, Übersetzbarkeit und das Verbot von „bypass node access“.
 */
#[Group('tannwald')]
final class ContentModelTest extends UnitTestCase {

  /**
   * Vertragstabelle: Bundle => Feld => [Typ, Kardinalität, Pflicht, Ziel].
   *
   * Ziel ist das Vokabular oder Node-Bundle einer Referenz, sonst NULL.
   * field_maschinentyp hat eine gemeinsame, unbegrenzte Storage (siehe
   * Bericht 2.1): maschine ist einwertig per Konvention.
   */
  private const NODE_FIELDS = [
    'dokument' => [
      'body' => ['text_with_summary', 1, TRUE, NULL],
      'field_dokumenttyp' => ['entity_reference', 1, TRUE, 'dokumenttyp'],
      'field_maschinentyp' => ['entity_reference', -1, TRUE, 'maschinentyp'],
      'field_seriennummer' => ['entity_reference', 1, FALSE, 'maschine'],
      'field_version' => ['string', 1, TRUE, NULL],
      'field_aenderungsvermerk' => ['string_long', 1, TRUE, NULL],
      'field_exportklasse' => ['list_string', 1, TRUE, NULL],
      'field_freigegeben_am' => ['datetime', 1, FALSE, NULL],
      'field_datei' => ['file', 1, FALSE, NULL],
      'field_gueltig_bis' => ['datetime', 1, FALSE, NULL],
    ],
    'bulletin' => [
      'body' => ['text_with_summary', 1, TRUE, NULL],
      'field_bulletin_art' => ['list_string', 1, TRUE, NULL],
      'field_maschinentyp' => ['entity_reference', -1, FALSE, 'maschinentyp'],
      'field_seriennummern' => ['entity_reference', -1, FALSE, 'maschine'],
      'field_bestaetigung_pflicht' => ['boolean', 1, FALSE, NULL],
    ],
    'zusammenfassung' => [
      'body' => ['text_with_summary', 1, TRUE, NULL],
      'field_dokument' => ['entity_reference', 1, TRUE, 'dokument'],
      'field_dokument_revision' => ['integer', 1, TRUE, NULL],
      'field_modell' => ['string', 1, FALSE, NULL],
      'field_generiert_am' => ['datetime', 1, FALSE, NULL],
    ],
    'maschine' => [
      'field_maschinentyp' => ['entity_reference', -1, TRUE, 'maschinentyp'],
      'field_firma' => ['entity_reference', 1, TRUE, 'firma'],
      'field_standort' => ['string', 1, FALSE, NULL],
      'field_baujahr' => ['integer', 1, FALSE, NULL],
      'field_vertrag' => ['list_string', 1, FALSE, NULL],
      'field_vertrag_bis' => ['datetime', 1, FALSE, NULL],
    ],
  ];

  /**
   * Vokabulare => Feld => [Typ, Kardinalität, Pflicht].
   */
  private const VOCABULARY_FIELDS = [
    'maschinentyp' => ['field_typ_code' => ['string', 1, TRUE]],
    'dokumenttyp' => ['field_slug' => ['string', 1, TRUE]],
    'firma' => [
      'field_sap_nr' => ['string', 1, FALSE],
      'field_land' => ['string', 1, FALSE],
      'field_firma_art' => ['list_string', 1, FALSE],
      'field_haendler_gebiet' => ['string', -1, FALSE],
    ],
  ];

  /**
   * Listenfelder => erlaubte Werte in dieser Reihenfolge.
   */
  private const LIST_VALUES = [
    'node.field_exportklasse' => ['keine', 'klasse_1', 'klasse_2'],
    'node.field_bulletin_art' => ['service', 'sicherheit'],
    'node.field_vertrag' => ['basis', 'service_plus'],
    'taxonomy_term.field_firma_art' => ['kunde', 'haendler'],
  ];

  private const ROLES = ['kunde', 'haendler', 'techniker', 'redaktion', 'compliance', 'portal_admin'];

  private const ALLOWED_HTML = '<h2 id> <h3 id> <p id> <table id> <thead id> <tbody id> <tr id> <th id> <td id> <ul id> <ol id> <li id> <strong id> <em id> <code id> <a href id>';

  /**
   * Jedes Feld der Vertragstabelle: Typ, Kardinalität, Pflicht, Ziel.
   */
  #[DataProvider('nodeFieldProvider')]
  public function testNodeField(string $bundle, string $field, string $type, int $cardinality, bool $required, ?string $target): void {
    $this->assertSame($bundle, $this->readSync("node.type.$bundle")['type'], "node.type.$bundle");
    $storage = $this->readSync("field.storage.node.$field");
    $instance = $this->readSync("field.field.node.$bundle.$field");
    $this->assertSame($type, $storage['type'], "$bundle.$field: Typ");
    $this->assertSame($cardinality, $storage['cardinality'], "$bundle.$field: Kardinalität");
    $this->assertSame($required, $instance['required'], "$bundle.$field: Pflicht");
    if ($target !== NULL) {
      $this->assertSame([$target => $target], $instance['settings']['handler_settings']['target_bundles'] ?? NULL, "$bundle.$field: Ziel");
    }
  }

  /**
   * Datenlieferant: alle Felder aller Bundles.
   */
  public static function nodeFieldProvider(): \Generator {
    foreach (self::NODE_FIELDS as $bundle => $fields) {
      foreach ($fields as $field => [$type, $cardinality, $required, $target]) {
        yield "$bundle.$field" => [$bundle, $field, $type, $cardinality, $required, $target];
      }
    }
  }

  /**
   * Genau vier eigene Bundles, drei moderiert und übersetzbar, maschine nicht.
   */
  public function testBundlesTranslationAndRevisions(): void {
    foreach (array_keys(self::NODE_FIELDS) as $bundle) {
      $type = $this->readSync("node.type.$bundle");
      $this->assertTrue($type['new_revision'], "$bundle: neue Revision standardmässig");
      $this->assertSame(0, $type['preview_mode'], "$bundle: Vorschau aus");
      $language = $this->readSync("language.content_settings.node.$bundle");
      $translatable = $bundle !== 'maschine';
      $this->assertSame($translatable, $language['third_party_settings']['content_translation']['enabled'] ?? FALSE, "$bundle: Übersetzung");
      $this->assertFalse($language['language_alterable'], "$bundle: Sprachauswahl versteckt");
    }
    $translatableFields = [
      'dokument' => ['body', 'field_aenderungsvermerk', 'field_datei'],
      'bulletin' => ['body'],
      'zusammenfassung' => ['body'],
    ];
    foreach ($translatableFields as $bundle => $fields) {
      foreach (array_keys(self::NODE_FIELDS[$bundle]) as $field) {
        $this->assertSame(in_array($field, $fields, TRUE), $this->readSync("field.field.node.$bundle.$field")['translatable'], "$bundle.$field: übersetzbar");
      }
    }
    $this->assertSame(['dokument_html'], $this->readSync('field.field.node.dokument.body')['settings']['allowed_formats'], 'body: nur dokument_html');
    $datei = $this->readSync('field.field.node.dokument.field_datei')['settings'];
    $this->assertSame('private', $this->readSync('field.storage.node.field_datei')['settings']['uri_scheme'], 'field_datei: privat');
    $this->assertSame('dokumente', $datei['file_directory'], 'field_datei: Verzeichnis');
    $this->assertSame([['value' => 'keine']], $this->readSync('field.field.node.dokument.field_exportklasse')['default_value'], 'field_exportklasse: Standard keine');
  }

  /**
   * Vokabulare mit ihren Feldern.
   */
  public function testVocabularies(): void {
    foreach (self::VOCABULARY_FIELDS as $vid => $fields) {
      $this->assertSame($vid, $this->readSync("taxonomy.vocabulary.$vid")['vid'], "Vokabular $vid");
      foreach ($fields as $field => [$type, $cardinality, $required]) {
        $storage = $this->readSync("field.storage.taxonomy_term.$field");
        $this->assertSame($type, $storage['type'], "$vid.$field: Typ");
        $this->assertSame($cardinality, $storage['cardinality'], "$vid.$field: Kardinalität");
        $this->assertSame($required, $this->readSync("field.field.taxonomy_term.$vid.$field")['required'], "$vid.$field: Pflicht");
      }
    }
    foreach (['field_firma' => 'entity_reference', 'field_land' => 'string'] as $field => $type) {
      $this->assertSame($type, $this->readSync("field.storage.user.$field")['type'], "user.$field: Typ");
      $this->assertFalse($this->readSync("field.field.user.user.$field")['required'], "user.$field: optional");
    }
  }

  /**
   * Listenfelder tragen genau die Werte des Vertrags.
   */
  public function testListValues(): void {
    foreach (self::LIST_VALUES as $storage => $values) {
      $allowed = array_column($this->readSync("field.storage.$storage")['settings']['allowed_values'], 'value');
      $this->assertSame($values, $allowed, "$storage: Listenwerte");
    }
  }

  /**
   * Sechs Rollen, content_editor weg, bypass node access nur für administrator.
   */
  public function testRolesAndBypass(): void {
    foreach (self::ROLES as $rid) {
      $role = $this->readSync("user.role.$rid");
      $this->assertContains('access content', $role['permissions'], "$rid: access content");
      $this->assertNotContains('bypass node access', $role['permissions'], "$rid: kein bypass node access");
    }
    $this->assertFileDoesNotExist($this->syncFile('user.role.content_editor'), 'Rolle content_editor ist gelöscht');
    foreach (glob($this->syncFile('user.role.*')) as $file) {
      $role = Yaml::decode((string) file_get_contents($file));
      if ($role['id'] !== 'administrator') {
        $this->assertNotContains('bypass node access', $role['permissions'] ?? [], basename($file) . ': kein bypass node access');
      }
    }
    $this->assertNotContains('create zusammenfassung content', $this->readSync('user.role.redaktion')['permissions'], 'redaktion legt keine Zusammenfassung an');
    $this->assertContains('create zusammenfassung content', $this->readSync('user.role.portal_admin')['permissions'], 'portal_admin legt Zusammenfassungen an');
    // Vier-Augen-Prinzip (RunBook 3.2): compliance gibt frei; Speichern
    // verlangt das Textformat.
    foreach (['use redaktion transition freigeben', 'use text format dokument_html'] as $permission) {
      $this->assertContains($permission, $this->readSync('user.role.compliance')['permissions'], "compliance: $permission");
    }
  }

  /**
   * Workflow redaktion: vier Status, vier Transitionen, drei Bundles.
   */
  public function testWorkflow(): void {
    $workflow = $this->readSync('workflows.workflow.redaktion');
    $this->assertSame('content_moderation', $workflow['type']);
    $settings = $workflow['type_settings'];
    $states = $settings['states'];
    $this->assertEqualsCanonicalizing(['draft', 'zur_pruefung', 'published', 'zurueckgewiesen'], array_keys($states), 'Status');
    foreach ($states as $id => $state) {
      $this->assertSame($id === 'published', $state['published'], "Status $id: veröffentlicht");
    }
    $this->assertSame('draft', $settings['default_moderation_state'], 'Standardstatus');
    $expected = [
      'zur_pruefung_senden' => [['draft', 'zurueckgewiesen'], 'zur_pruefung'],
      'freigeben' => [['zur_pruefung'], 'published'],
      'zurueckweisen' => [['zur_pruefung'], 'zurueckgewiesen'],
      'entwurf_speichern' => [['draft', 'published', 'zur_pruefung', 'zurueckgewiesen'], 'draft'],
    ];
    $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($settings['transitions']), 'Transitionen');
    foreach ($expected as $id => [$from, $to]) {
      $this->assertEqualsCanonicalizing($from, $settings['transitions'][$id]['from'], "$id: von");
      $this->assertSame($to, $settings['transitions'][$id]['to'], "$id: nach");
    }
    $this->assertEqualsCanonicalizing(['dokument', 'bulletin', 'zusammenfassung'], $settings['entity_types']['node'], 'moderierte Bundles');
  }

  /**
   * Textformat dokument_html mit genau der Tag-Liste.
   */
  public function testTextFormat(): void {
    $filters = $this->readSync('filter.format.dokument_html')['filters'];
    $this->assertTrue($filters['filter_html']['status'], 'filter_html an');
    $this->assertSame(self::ALLOWED_HTML, $filters['filter_html']['settings']['allowed_html'], 'erlaubte Tags');
    $this->assertTrue($filters['filter_htmlcorrector']['status'], 'filter_htmlcorrector an');
    foreach (['filter_autop', 'filter_url'] as $off) {
      $this->assertFalse($filters[$off]['status'] ?? FALSE, "$off aus");
    }
  }

  /**
   * View portal_maschinen unter /meine-maschinen mit Node-Access.
   */
  public function testPortalMaschinenView(): void {
    $view = $this->readSync('views.view.portal_maschinen');
    $page = $view['display']['page_1']['display_options'];
    $default = $view['display']['default']['display_options'];
    $this->assertSame('meine-maschinen', $page['path'], 'Pfad');
    $this->assertSame('normal', $page['menu']['type'], 'Menüpunkt: Typ');
    $this->assertSame('Meine Maschinen', $page['menu']['title'], 'Menüpunkt: Titel');
    $this->assertSame('main', $page['menu']['menu_name'], 'Menüpunkt: Hauptmenü');
    $this->assertSame('Meine Maschinen', $default['title'], 'Titel');
    $this->assertFalse($default['query']['options']['disable_sql_rewrite'], 'SQL-Rewriting an (Node Access)');
    $this->assertSame('access content', $default['access']['options']['perm'], 'Zugriff');
    $this->assertSame(['maschine' => 'maschine'], $default['filters']['type']['value'], 'nur maschine');
    $columns = [
      'title',
      'field_maschinentyp',
      'field_standort',
      'field_baujahr',
      'field_vertrag',
      'field_vertrag_bis',
    ];
    $this->assertSame($columns, array_keys($default['fields']), 'Spalten');
  }

  /**
   * Pfad einer Datei in config/sync.
   */
  private function syncFile(string $name): string {
    return dirname(__DIR__, 3) . '/config/sync/' . $name . '.yml';
  }

  /**
   * Liest eine Datei aus config/sync als Array.
   */
  private function readSync(string $name): array {
    $file = $this->syncFile($name);
    $this->assertFileExists($file);
    $data = Yaml::decode((string) file_get_contents($file));
    $this->assertIsArray($data, "$name.yml ist kein YAML-Mapping");
    return $data;
  }

}
