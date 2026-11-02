<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;

/**
 * Wächter D5: Bezeichner englisch, ausser den Namen des Pflichtenhefts.
 *
 * Liest alle PHP-, YAML- und Twig-Dateien unter web/modules/custom und
 * web/themes/custom und meldet Bezeichner (Klassen, Interfaces, Traits,
 * Funktionen, Methoden, Konstanten, Variablen ab 6 Zeichen, YAML-Schlüssel
 * wie Service-IDs, Routen und Konfigschlüssel, Routenpfade, Twig-Variablen)
 * mit deutschen Wortstämmen, die nicht durch einen Eintrag in ALLOWED gedeckt
 * sind. Kommentare und Zeichenketten werden nicht geprüft (Kommentare sind
 * deutsch, Oberflächentexte laufen über translations/de.po).
 *
 * Wer einen neuen Pflichtenheft-Namen einführt, trägt ihn in ALLOWED nach.
 */
#[Group('tannwald_access')]
final class IdentifierLanguageTest extends UnitTestCase {

  /**
   * Namen aus Pflichtenheft und RunBook §1.1 (Projektklausel D5), wörtlich.
   */
  public const ALLOWED = [
    // Realms (Pflichtenheft §5.5) und Parameter der Vertragssignaturen.
    'role:intern', 'klasse',
    // Bundles, Vokabulare, Textformat.
    'dokument', 'bulletin', 'zusammenfassung', 'maschine', 'maschinentyp', 'dokumenttyp', 'firma', 'dokument_html',
    // Felder.
    'field_dokumenttyp', 'field_maschinentyp', 'field_seriennummer', 'field_seriennummern', 'field_version',
    'field_aenderungsvermerk', 'field_exportklasse', 'field_freigegeben_am', 'field_datei', 'field_gueltig_bis',
    'field_bulletin_art', 'field_bestaetigung_pflicht', 'field_dokument', 'field_dokument_revision', 'field_modell',
    'field_generiert_am', 'field_firma', 'field_standort', 'field_baujahr', 'field_vertrag', 'field_vertrag_bis',
    'field_typ_code', 'field_slug', 'field_sap_nr', 'field_land', 'field_firma_art', 'field_haendler_gebiet',
    'field_sprache',
    // Rollen, Workflow, Listenwerte.
    'kunde', 'haendler', 'techniker', 'redaktion', 'compliance', 'portal_admin',
    'draft', 'entwurf', 'zur_pruefung', 'published', 'zurueckgewiesen',
    'zur_pruefung_senden', 'freigeben', 'zurueckweisen', 'entwurf_speichern',
    'keine', 'klasse_1', 'klasse_2', 'service', 'sicherheit', 'basis', 'service_plus',
    // Entities, Tabellen, Spalten.
    'lesebestaetigung', 'support_anfrage', 'bulletin_nid', 'maschine_nid', 'bestaetigt_am', 'ip_land',
    'tannwald_chunk', 'tannwald_assistant_log', 'tannwald_usage', 'tannwald_audit',
    'chunk_index', 'heading_path', 'access_keys', 'exportklasse', 'dokumenttyp_tid', 'maschinentyp_tids',
    'token_count', 'session_hash', 'uid_hash', 'frage', 'antwort', 'quellen', 'bewertung', 'dauer_ms', 'modell',
    'prompt_tokens', 'completion_tokens', 'kosten_chf', 'kontext', 'ereignis', 'verlauf',
    // Audit-Ereignisse und details-Schlüssel.
    'download', 'zugriff_verweigert', 'freigabe', 'exportregel_geaendert', 'von', 'nach', 'vorher', 'nachher',
    // Konfiguration.
    'tannwald_access.settings', 'tannwald_assistant.settings', 'export_rules', 'laender', 'gesperrte_firmen',
    'chat_model', 'embedding_model', 'top_k', 'min_score', 'monthly_token_limit', 'kosten_pro_1k_chf',
    'safety_terms', 'assistant_enabled',
    // Assistent, Suche, API.
    'assistent', 'ingest', 'beantwortet', 'abgelehnt', 'safety', 'limit', 'search_only', 'modus', 'hybrid',
    'volltext', 'matched_by', 'antwort_html', 'titel', 'log_id', 'support_anfrage_id', 'treffer', 'snippet_html',
    'maschinentypen', 'sprache', 'aktuell', 'url_kapitel', 'url_dokument', 'url_maschine',
    'bulletin_bestaetigt_am', 'tannwald_search_page', 'kapitel',
    // Queues, Drush, Migrationen, Module, Views.
    'tannwald_embed', 'tannwald_notify', 'tannwald:demo-install', 'tannwald:demo-reset', 'tannwald:reindex',
    'tannwald:queue-run', 'tannwald:eval', 'tannwald:log-purge', 'tannwald_firmen', 'tannwald_maschinen',
    'tannwald_dokumente', 'tannwald_bulletins', 'tannwald_demo', 'tannwald_bulletin', 'portal_maschinen',
    'redaktion_freigaben', 'redaktion_bestaetigungen', 'redaktion_unbeantwortet', 'admin_nutzung', 'admin_kosten',
    // Routenpfade.
    '/compliance/exportregeln', '/dokument/{node}/download', '/bulletin/{node}/bestaetigen',
    '/redaktion/zusammenfassung/{node}/{transition}', '/redaktion/freigaben', '/redaktion/unbeantwortet',
    '/redaktion/support-anfragen', '/redaktion/bulletins/{node}/bestaetigungen', '/meine-maschinen', '/suche',
    '/admin/config/tannwald/assistent', '/admin/tannwald/nutzung', '/admin/tannwald/kosten',
    // Fixture-Manifeste und Eval-Set.
    'injection_test', 'versionen', 'datei', 'aenderungsvermerk', 'freigegeben_am', 'gueltig_bis', 'art',
    'seriennummer', 'seriennummern', 'bestaetigung_pflicht', 'veroeffentlicht_am', 'erstellt_von', 'sap_nr',
    'land', 'haendler_gebiet', 'haendler_zuordnung', 'firma_sap_nr', 'standort', 'baujahr', 'vertrag',
    'vertrag_bis', 'anzeigename', 'rolle', 'erwartete_quellen', 'erwartet', 'ablehnung', 'zitat_korrekt',
    'ablehnung_korrekt', 'safety_korrekt',
  ];

  /**
   * Deutsche Wortstämme (ohne Umlaute; Normalisierung ä → ae …).
   *
   * Bewusst ohne Stämme, die in englischen Wörtern stecken ("liste" in
   * listener, "rolle" in controller, "stand" in standard).
   */
  public const GERMAN_STEMS = [
    'datenschutz', 'dokument', 'maschine', 'freigabe', 'freigeb', 'pruef', 'nutzer', 'firma', 'firmen', 'klasse',
    'nummer', 'erstell', 'gesperrt', 'sperr', 'laender', 'bestaetig', 'zusammenfass', 'anfrage', 'ereignis',
    'verlauf', 'einstellung', 'berechtigung', 'schluessel', 'zugriff', 'recht', 'datei', 'sprache', 'frage',
    'antwort', 'quelle', 'sicherheit', 'kunde', 'haendler', 'techniker', 'redaktion', 'vertrag', 'standort',
    'baujahr', 'gebiet', 'benachrichtig', 'aenderung', 'vermerk', 'gueltig', 'anlage', 'wartung', 'ersatzteil',
    'schaltplan', 'zertifikat', 'schulung', 'betrieb', 'kapitel', 'suche', 'treffer', 'hilfreich', 'kosten',
    'nutzung', 'ergebnis', 'sitzung', 'pflicht', 'verweiger', 'loesch', 'speicher', 'entwurf', 'zurueck',
    'eintrag', 'protokoll', 'ausgabe', 'eingabe', 'anzahl', 'zaehl', 'fehler', 'meldung', 'nachricht', 'seite',
    'datum', 'zeit', 'uebersetz', 'regel', 'inhalt', 'pfad', 'titel', 'konto', 'ansicht', 'schritt', 'menge',
    'gesamt', 'weiter', 'bestand', 'pruefung', 'ausfuehr', 'pruefen', 'gueltigkeit', 'verwalt',
  ];

  /**
   * Geprüfte Ordner relativ zur Drupal-Wurzel (web).
   */
  public const SCANNED_DIRECTORIES = ['modules/custom', 'themes/custom'];

  /**
   * Übersprungene Ordner (Fremdcode, Build-Ergebnisse).
   */
  private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor', 'dist'];

  /**
   * Der heutige Code hält D5 ein.
   */
  public function testCustomCodeUsesEnglishIdentifiers(): void {
    $findings = [];
    $files = 0;
    foreach (self::files($this->root) as $path) {
      $files++;
      $relative = substr($path, strlen($this->root) + 1);
      try {
        $identifiers = self::identifiersInFile($path);
      }
      catch (InvalidDataTypeException $exception) {
        $findings[] = $relative . ': YAML not parseable (' . $exception->getMessage() . ')';
        continue;
      }
      foreach (array_unique($identifiers) as $identifier) {
        if (self::isGerman($identifier)) {
          $findings[] = $relative . ': ' . $identifier;
        }
      }
    }
    $this->assertGreaterThan(0, $files, 'No files scanned.');
    $this->assertSame([], $findings, 'German identifiers outside the D5 allowlist. Use English names, or add a name from the Pflichtenheft to IdentifierLanguageTest::ALLOWED.');
  }

  /**
   * Der Wächter erkennt deutsche Bezeichner und lässt Vertragsnamen durch.
   */
  public function testDetectorFlagsGermanIdentifiers(): void {
    $php = '<?php final class Nutzerliste { const GESPERRTE_NUTZER = 1; public function speichereDaten(): void { $datenschutz = TRUE; $field_exportklasse = 1; $exportClass = 2; $land = 3; } }';
    $identifiers = self::identifiersInPhp($php);
    $this->assertSame([
      'Nutzerliste',
      'GESPERRTE_NUTZER',
      'speichereDaten',
      'datenschutz',
      'field_exportklasse',
      'exportClass',
    ], $identifiers, 'Variables shorter than 6 characters are skipped.');
    $flagged = array_values(array_filter($identifiers, [self::class, 'isGerman']));
    $this->assertSame(['Nutzerliste', 'GESPERRTE_NUTZER', 'speichereDaten', 'datenschutz'], $flagged);

    $yaml = "export_rules:\n  klasse_1:\n    laender: [CH]\ndatenschutz_einstellung: 1\nroute.name:\n  path: '/compliance/exportregeln'\nother.route:\n  path: '/benutzer/loeschen'\n";
    $flagged = array_values(array_filter(self::identifiersInYaml($yaml), [self::class, 'isGerman']));
    $this->assertSame(['datenschutz_einstellung', '/benutzer/loeschen'], $flagged);

    $twig = '{{ hits }} {% set nutzername = x %} {% for treffer in hits %}{{ treffer.titel }}{% endfor %}';
    $flagged = array_values(array_filter(self::identifiersInTwig($twig), [self::class, 'isGerman']));
    $this->assertSame(['nutzername'], $flagged);

    $this->assertFalse(self::isGerman('zurPruefungSenden'), 'camelCase of an allowed name.');
    $this->assertFalse(self::isGerman('tannwald_access.download'));
    $this->assertTrue(self::isGerman('$datenschutz'));
  }

  /**
   * Dateien unter den geprüften Ordnern.
   *
   * @return string[]
   *   Absolute Pfade, sortiert.
   */
  private static function files(string $root): array {
    $paths = [];
    foreach (self::SCANNED_DIRECTORIES as $directory) {
      $base = $root . '/' . $directory;
      if (!is_dir($base)) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
          new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
          static fn(\SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, TRUE)),
        ),
      );
      foreach ($iterator as $file) {
        if ($file instanceof \SplFileInfo && $file->isFile() && self::kindOf($file->getFilename()) !== NULL) {
          $paths[] = $file->getPathname();
        }
      }
    }
    sort($paths);
    return $paths;
  }

  /**
   * Dateiart nach Endung.
   */
  private static function kindOf(string $filename): ?string {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($extension) {
      'php', 'module', 'install', 'inc', 'theme', 'profile', 'engine' => 'php',
      'yml', 'yaml' => 'yaml',
      'twig' => 'twig',
      default => NULL,
    };
  }

  /**
   * Bezeichner einer Datei.
   *
   * @return string[]
   *   Bezeichner.
   */
  private static function identifiersInFile(string $path): array {
    $content = (string) file_get_contents($path);
    return match (self::kindOf(basename($path))) {
      'php' => self::identifiersInPhp($content),
      'yaml' => self::identifiersInYaml($content),
      'twig' => self::identifiersInTwig($content),
      default => [],
    };
  }

  /**
   * Bezeichner aus PHP-Code (Token, ohne Kommentare und Zeichenketten).
   *
   * @return string[]
   *   Bezeichner in Reihenfolge des Auftretens.
   */
  private static function identifiersInPhp(string $code): array {
    $tokens = token_get_all($code);
    $count = count($tokens);
    $declarations = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST];
    $identifiers = [];
    for ($i = 0; $i < $count; $i++) {
      $token = $tokens[$i];
      if (!is_array($token)) {
        continue;
      }
      if ($token[0] === T_VARIABLE) {
        $name = substr($token[1], 1);
        if ($name !== 'this' && strlen($name) >= 6) {
          $identifiers[] = $name;
        }
        continue;
      }
      if (!in_array($token[0], $declarations, TRUE)) {
        continue;
      }
      $previous = self::significantToken($tokens, $i, -1);
      if ($token[0] === T_CLASS && is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
        // Foo::class.
        continue;
      }
      $next = self::significantToken($tokens, $i, 1);
      if (is_array($next) && $next[0] === T_STRING) {
        $identifiers[] = $next[1];
      }
    }
    return $identifiers;
  }

  /**
   * Nächstes Token ohne Leerraum und Referenzzeichen.
   *
   * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
   *   Token.
   * @param int $index
   *   Startindex.
   * @param int $step
   *   Richtung (1 oder -1).
   *
   * @return array{0: int, 1: string, 2: int}|string|null
   *   Token oder NULL.
   */
  private static function significantToken(array $tokens, int $index, int $step): array|string|null {
    for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
      $token = $tokens[$i];
      if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      if ($token === '&' || (is_array($token) && in_array($token[1], ['&'], TRUE))) {
        continue;
      }
      return $token;
    }
    return NULL;
  }

  /**
   * Bezeichner aus YAML: alle Schlüssel und Werte von "path".
   *
   * @return string[]
   *   Bezeichner.
   */
  private static function identifiersInYaml(string $yaml): array {
    $data = Yaml::decode($yaml);
    $identifiers = [];
    $walk = static function (mixed $node) use (&$walk, &$identifiers): void {
      if (!is_array($node)) {
        return;
      }
      foreach ($node as $key => $value) {
        if (is_string($key)) {
          $identifiers[] = $key;
          if ($key === 'path' && is_string($value)) {
            $identifiers[] = $value;
          }
        }
        $walk($value);
      }
    };
    $walk($data);
    return $identifiers;
  }

  /**
   * Bezeichner aus Twig: Ausgaben, set, for, macro.
   *
   * @return string[]
   *   Bezeichner.
   */
  private static function identifiersInTwig(string $twig): array {
    $identifiers = [];
    preg_match_all('/\{\{-?\s*([A-Za-z_][A-Za-z0-9_]*)/', $twig, $output);
    preg_match_all('/\{%-?\s*(?:set|for|macro)\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*,\s*([A-Za-z_][A-Za-z0-9_]*))?/', $twig, $statements);
    foreach (array_merge($output[1], $statements[1], $statements[2]) as $name) {
      if ($name !== '') {
        $identifiers[] = $name;
      }
    }
    return array_values(array_unique($identifiers));
  }

  /**
   * Ob ein Bezeichner deutsche Stämme ausserhalb der Erlaubnisliste trägt.
   */
  private static function isGerman(string $identifier): bool {
    $normalized = self::normalize($identifier);
    if ($normalized === '') {
      return FALSE;
    }
    foreach (self::normalizedAllowed() as $allowed) {
      // Ersetzen durch einen Trenner, damit keine neuen Wörter entstehen.
      $normalized = str_replace($allowed, ' ', $normalized);
    }
    foreach (self::GERMAN_STEMS as $stem) {
      if (str_contains($normalized, $stem)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Erlaubnisliste normalisiert, längste zuerst.
   *
   * @return string[]
   *   Normalisierte Einträge.
   */
  private static function normalizedAllowed(): array {
    static $allowed = NULL;
    if ($allowed === NULL) {
      $allowed = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], self::ALLOWED))));
      usort($allowed, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }
    return $allowed;
  }

  /**
   * Kleinbuchstaben, Umlaute ausgeschrieben, nur a–z und 0–9.
   */
  private static function normalize(string $value): string {
    $value = strtr(mb_strtolower($value), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return (string) preg_replace('/[^a-z0-9]/', '', $value);
  }

}
