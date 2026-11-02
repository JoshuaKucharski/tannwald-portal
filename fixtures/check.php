<?php

/**
 * @file
 * Prüfskript für die Fixtures des myTannwald PoC (Aufgabe 2.2).
 *
 * Aufruf: php fixtures/check.php (vom Repo-Stamm oder von überall; die Pfade
 * werden relativ zu dieser Datei aufgelöst). Keine Abhängigkeiten, kein
 * vendor/, läuft auf dem Host und im Container. Jede Prüfung gibt eine Zeile
 * "ok: …" oder "FEHLER: … (<datei>[:<zeile>])" aus; am Ende "OK — <Zählungen>"
 * mit Exit 0 oder "FEHLER: n Befunde" mit Exit 1.
 *
 * Die Schlüssel der Manifeste und des Eval-Sets folgen dem 1.1-Vertrag des
 * RunBooks (Projektklausel D5: deutsche Schlüssel aus dem Pflichtenheft bleiben
 * wörtlich); die Bezeichner dieses Skripts sind englisch.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Konstanten des Vertrags.
// -----------------------------------------------------------------------------

/** Erlaubte Tags des Textformats dokument_html (RunBook 1.1). */
const ALLOWED_TAGS = ['h2', 'h3', 'p', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'a'];

/** Erlaubte Attribute je Tag; alle übrigen Attribute sind Befunde. */
const ALLOWED_ATTRIBUTES = ['id' => ALLOWED_TAGS, 'href' => ['a']];

/** Dokumenttyp-Slugs (Taxonomie dokumenttyp, field_slug). */
const DOCUMENT_TYPES = ['betriebsanleitung', 'ersatzteilkatalog', 'schaltplan', 'wartungsplan', 'zertifikat', 'schulungsunterlage', 'anlagenspezifisch'];

/** Maschinentyp-Terme (Schreibweise mit Bindestrich wie die Terme). */
const MACHINE_TYPES = ['TB-200', 'TB-400', 'KT-500'];

/** Exportklassen (field_exportklasse). */
const EXPORT_CLASSES = ['keine', 'klasse_1', 'klasse_2'];

/** Sprachen der Site. */
const LANGCODES = ['de', 'en', 'fr'];

/** Klasse-2-Länderliste des Seeders (RunBook 2.3); das Händlerland liegt ausserhalb. */
const CLASS_2_COUNTRIES = ['CH', 'DE', 'AT', 'FR', 'IT', 'NL', 'US', 'JP'];

/** Die sechs Demo-Konten (RunBook 1.1). */
const DEMO_USERS = ['kunde-a', 'kunde-b', 'haendler', 'techniker', 'redaktion', 'compliance'];

/** Rollen der Demo-Konten. */
const ROLES = ['kunde', 'haendler', 'techniker', 'redaktion', 'compliance', 'portal_admin'];

/** Fehlercode der Demo (Wireframe 02): genau einmal je Datei der Betriebsanleitung TB-200, sonst nirgends. */
const DEMO_ERROR_CODE = 'E-4711';

/** Manifest-id des Dokuments, das den Fehlercode tragen darf. */
const DEMO_ERROR_CODE_DOCUMENT = 'ba-tb200';

/** Seriennummer des Leak-Tests (TC-02). */
const LEAK_TEST_SERIAL = 'TB200-2021-0417';

/** Phrase, die nirgends stehen darf (TC-06: nur Bedeutung, kein Wortmatch). */
const FORBIDDEN_PHRASES = ['Folie reisst beim Siegeln', 'Folie reißt beim Siegeln'];

// -----------------------------------------------------------------------------
// Befunde sammeln.
// -----------------------------------------------------------------------------

/** Sammelt Befunde und gibt Zeilen aus. */
final class Report {

  /** @var list<string> */
  private array $errors = [];

  public function ok(string $message): void {
    echo "ok: {$message}\n";
  }

  public function error(string $message, ?string $file = NULL, ?int $line = NULL): void {
    $location = '';
    if ($file !== NULL) {
      $location = ' (' . $file . ($line !== NULL ? ':' . $line : '') . ')';
    }
    $this->errors[] = $message . $location;
    echo "FEHLER: {$message}{$location}\n";
  }

  /** Gibt "ok" aus, wenn die Bedingung gilt, sonst einen Befund. */
  public function check(bool $condition, string $okMessage, string $errorMessage, ?string $file = NULL, ?int $line = NULL): bool {
    if ($condition) {
      $this->ok($okMessage);
    }
    else {
      $this->error($errorMessage, $file, $line);
    }
    return $condition;
  }

  public function errorCount(): int {
    return count($this->errors);
  }

}

// -----------------------------------------------------------------------------
// Hilfsfunktionen: Laden, Parsen, Slugs.
// -----------------------------------------------------------------------------

/** Liest eine JSON-Datei als Liste von Maps; wirft bei ungültigem JSON. */
function load_json(string $path): array {
  $data = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($data)) {
    throw new RuntimeException("JSON ist keine Liste: {$path}");
  }
  return $data;
}

/**
 * Kleiner YAML-Leser für genau das flache Format des Eval-Sets: eine Liste von
 * Maps mit Skalaren und einer Liste von Maps (erwartete_quellen). Unterstützt
 * Kommentare, doppelt und einfach gezitierte Strings, ungezitierte Skalare und
 * leere Listen ([]). Kein vollständiger YAML-Parser; ruby/python prüfen gegen.
 */
function parse_flat_yaml(string $path): array {
  $lines = file($path, FILE_IGNORE_NEW_LINES);
  if ($lines === FALSE) {
    throw new RuntimeException("Datei nicht lesbar: {$path}");
  }
  $items = [];
  $current = NULL;
  $listKey = NULL;
  $listItem = NULL;
  $closeListItem = function () use (&$current, &$listKey, &$listItem): void {
    if ($listItem !== NULL && $listKey !== NULL) {
      $current[$listKey][] = $listItem;
      $listItem = NULL;
    }
  };
  $closeItem = function () use (&$items, &$current, &$listKey, &$listItem, $closeListItem): void {
    $closeListItem();
    if ($current !== NULL) {
      $items[] = $current;
      $current = NULL;
      $listKey = NULL;
    }
  };
  foreach ($lines as $index => $raw) {
    $lineNo = $index + 1;
    $line = preg_replace('/\s+#.*$/', '', rtrim($raw));
    if ($line === '' || preg_match('/^\s*#/', $line)) {
      continue;
    }
    $indent = strlen($line) - strlen(ltrim($line));
    $trimmed = ltrim($line);
    if ($indent === 0 && str_starts_with($trimmed, '- ')) {
      // Neues Element der obersten Liste.
      $closeItem();
      $current = ['_line' => $lineNo];
      [$key, $value] = parse_yaml_pair(substr($trimmed, 2), $path, $lineNo);
      $current[$key] = $value;
    }
    elseif ($indent === 2 && !str_starts_with($trimmed, '- ')) {
      // Schlüssel des aktuellen Elements.
      $closeListItem();
      $listKey = NULL;
      [$key, $value] = parse_yaml_pair($trimmed, $path, $lineNo);
      if ($value === '__LIST__') {
        $current[$key] = [];
        $listKey = $key;
      }
      else {
        $current[$key] = $value;
      }
    }
    elseif ($indent === 4 && str_starts_with($trimmed, '- ') && $listKey !== NULL) {
      // Neues Element der verschachtelten Liste.
      $closeListItem();
      $listItem = ['_line' => $lineNo];
      [$key, $value] = parse_yaml_pair(substr($trimmed, 2), $path, $lineNo);
      $listItem[$key] = $value;
    }
    elseif ($indent === 6 && $listItem !== NULL) {
      [$key, $value] = parse_yaml_pair($trimmed, $path, $lineNo);
      $listItem[$key] = $value;
    }
    else {
      throw new RuntimeException("YAML-Zeile nicht im erwarteten flachen Format ({$path}:{$lineNo})");
    }
  }
  $closeItem();
  return $items;
}

/** Zerlegt "key: value" in Schlüssel und Skalar; "key:" ohne Wert → "__LIST__", "[]" → leere Liste. */
function parse_yaml_pair(string $text, string $path, int $lineNo): array {
  if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*):(?:\s+(.*))?$/u', $text, $m)) {
    throw new RuntimeException("YAML-Paar nicht lesbar ({$path}:{$lineNo}): {$text}");
  }
  $key = $m[1];
  $value = isset($m[2]) ? trim($m[2]) : '';
  if ($value === '') {
    return [$key, '__LIST__'];
  }
  if ($value === '[]') {
    return [$key, []];
  }
  if (preg_match('/^"(.*)"$/u', $value, $q)) {
    return [$key, str_replace(['\\"', '\\\\'], ['"', '\\'], $q[1])];
  }
  if (preg_match("/^'(.*)'$/u", $value, $q)) {
    return [$key, str_replace("''", "'", $q[1])];
  }
  return [$key, $value];
}

/** HeadingSlug nach RunBook 1.1: NFC, Kleinbuchstaben, Umlaute, Akzente weg, Rest → "-", gestutzt, verdichtet. */
function heading_slug(string $heading): string {
  $text = $heading;
  if (class_exists('Normalizer')) {
    $text = (string) Normalizer::normalize($text, Normalizer::FORM_C);
  }
  $text = mb_strtolower($text, 'UTF-8');
  $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
  if (class_exists('Normalizer')) {
    // Akzente abtrennen und entfernen (é → e).
    $text = (string) Normalizer::normalize($text, Normalizer::FORM_D);
    $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
  }
  $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
  $text = trim($text, '-');
  return (string) preg_replace('/-{2,}/', '-', $text);
}

/** Liefert die h2/h3-Überschriften einer HTML-Datei (Text ohne Tags, dekodiert). */
function headings_of(string $html): array {
  preg_match_all('#<(h2|h3)(?:\s[^>]*)?>(.*?)</\1>#su', $html, $m);
  return array_map(static fn(string $h): string => trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[2]);
}

/** Wortzahl des Textes ohne Tags (Tags werden durch Leerzeichen ersetzt). */
function word_count_of(string $html): int {
  $text = (string) preg_replace('/<[^>]+>/', ' ', $html);
  $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
  return count($words);
}

/** Prüft Tags und Attribute einer HTML-Datei und meldet Befunde mit Zeilennummer. */
function check_html_tags(Report $report, string $relPath, string $html): bool {
  $clean = TRUE;
  $lines = explode("\n", $html);
  foreach ($lines as $index => $line) {
    $lineNo = $index + 1;
    // Alle Start- und Endtags der Zeile.
    preg_match_all('#<(/?)([a-z0-9]+)([^>]*)>#i', $line, $tags, PREG_SET_ORDER);
    foreach ($tags as $tag) {
      $name = strtolower($tag[2]);
      if (!in_array($name, ALLOWED_TAGS, TRUE)) {
        $report->error("unerlaubtes Tag <{$name}>", $relPath, $lineNo);
        $clean = FALSE;
        continue;
      }
      if ($tag[1] === '/') {
        continue;
      }
      preg_match_all('/\s([a-z][a-z0-9-]*)\s*=/i', $tag[3], $attrs);
      foreach ($attrs[1] as $attr) {
        $attr = strtolower($attr);
        if (!isset(ALLOWED_ATTRIBUTES[$attr]) || !in_array($name, ALLOWED_ATTRIBUTES[$attr], TRUE)) {
          $report->error("unerlaubtes Attribut {$attr} an <{$name}>", $relPath, $lineNo);
          $clean = FALSE;
        }
      }
    }
    if (preg_match('/<!--|<\?|<!/', $line)) {
      $report->error('Kommentar oder Deklaration im HTML', $relPath, $lineNo);
      $clean = FALSE;
    }
  }
  return $clean;
}

/** Erste Zeilennummer, in der ein Begriff vorkommt (1-basiert), oder NULL. */
function first_line_of(string $content, string $needle): ?int {
  $pos = strpos($content, $needle);
  if ($pos === FALSE) {
    return NULL;
  }
  return substr_count(substr($content, 0, $pos), "\n") + 1;
}

/** Prüft ein Datum im Format YYYY-MM-DD. */
function is_iso_date(mixed $value): bool {
  if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
    return FALSE;
  }
  [$y, $m, $d] = array_map('intval', explode('-', $value));
  return checkdate($m, $d, $y);
}

// -----------------------------------------------------------------------------
// Hauptprogramm.
// -----------------------------------------------------------------------------

$report = new Report();
$root = dirname(__DIR__);
$fixtures = __DIR__;
$rel = static fn(string $path): string => ltrim(str_replace($root, '', $path), '/');

$errorsBefore = static fn(): int => $report->errorCount();

// --- 1. SAP-Mock -------------------------------------------------------------

$companiesPath = "{$fixtures}/sap/firmen.json";
$machinesPath = "{$fixtures}/sap/maschinen.json";
$usersPath = "{$fixtures}/sap/nutzer.json";
$docManifestPath = "{$fixtures}/dokumente/manifest.json";
$bulletinManifestPath = "{$fixtures}/bulletins/manifest.json";
$evalPath = "{$root}/docs/evals/fragen.yaml";

foreach ([$companiesPath, $machinesPath, $usersPath, $docManifestPath, $bulletinManifestPath, $evalPath] as $path) {
  if (!is_file($path)) {
    $report->error('Datei fehlt', $rel($path));
  }
}
if ($report->errorCount() > 0) {
  echo "FEHLER: {$report->errorCount()} Befunde\n";
  exit(1);
}

try {
  $companies = load_json($companiesPath);
  $machines = load_json($machinesPath);
  $users = load_json($usersPath);
  $documents = load_json($docManifestPath);
  $bulletins = load_json($bulletinManifestPath);
  $report->ok('alle JSON-Dateien geladen (JSON_THROW_ON_ERROR)');
}
catch (Throwable $e) {
  $report->error('JSON nicht lesbar: ' . $e->getMessage());
  echo "FEHLER: {$report->errorCount()} Befunde\n";
  exit(1);
}

// Firmen.
$companyByNr = [];
$dealer = NULL;
foreach ($companies as $i => $c) {
  $where = $rel($companiesPath) . " [{$i}]";
  foreach (['sap_nr', 'name', 'land', 'art'] as $key) {
    if (!isset($c[$key]) || !is_string($c[$key]) || $c[$key] === '') {
      $report->error("Firma ohne {$key}", $where);
    }
  }
  if (isset($c['sap_nr'])) {
    if (isset($companyByNr[$c['sap_nr']])) {
      $report->error("sap_nr doppelt: {$c['sap_nr']}", $where);
    }
    $companyByNr[$c['sap_nr']] = $c;
  }
  if (isset($c['land']) && !preg_match('/^[A-Z]{2}$/', (string) $c['land'])) {
    $report->error("land nicht ISO-3166 alpha-2: {$c['land']}", $where);
  }
  if (isset($c['art']) && !in_array($c['art'], ['kunde', 'haendler'], TRUE)) {
    $report->error("art nicht kunde|haendler: {$c['art']}", $where);
  }
  if (($c['art'] ?? '') === 'haendler') {
    $dealer = $c;
    if (!isset($c['haendler_gebiet']) || !is_array($c['haendler_gebiet']) || $c['haendler_gebiet'] === []) {
      $report->error('Händlerfirma ohne haendler_gebiet', $where);
    }
    if (!isset($c['haendler_zuordnung']) || !is_array($c['haendler_zuordnung']) || $c['haendler_zuordnung'] === []) {
      $report->error('Händlerfirma ohne haendler_zuordnung', $where);
    }
  }
}
$report->check(count($companies) >= 6, 'Firmen: ' . count($companies) . ' (>= 6)', 'weniger als 6 Firmen', $rel($companiesPath));
$pharmalog = NULL;
foreach ($companies as $c) {
  if (($c['name'] ?? '') === 'Pharmalog AG') {
    $pharmalog = $c;
  }
}
$report->check($pharmalog !== NULL && ($pharmalog['land'] ?? '') === 'CH' && ($pharmalog['art'] ?? '') === 'kunde', 'Pharmalog AG vorhanden (CH, kunde)', 'Pharmalog AG fehlt oder nicht CH/kunde', $rel($companiesPath));
$report->check($dealer !== NULL, 'Händlerfirma vorhanden: ' . ($dealer['name'] ?? '-') . ' (' . ($dealer['land'] ?? '-') . ')', 'keine Händlerfirma', $rel($companiesPath));
if ($dealer !== NULL) {
  $report->check(!in_array($dealer['land'] ?? '', CLASS_2_COUNTRIES, TRUE), 'Händlerland ' . ($dealer['land'] ?? '-') . ' liegt ausserhalb der Klasse-2-Liste', 'Händlerland liegt in der Klasse-2-Liste', $rel($companiesPath));
  foreach ($dealer['haendler_zuordnung'] ?? [] as $nr) {
    if (!isset($companyByNr[$nr])) {
      $report->error("haendler_zuordnung verweist auf unbekannte sap_nr {$nr}", $rel($companiesPath));
    }
    elseif (($companyByNr[$nr]['art'] ?? '') !== 'kunde') {
      $report->error("haendler_zuordnung verweist auf Nicht-Kunde {$nr}", $rel($companiesPath));
    }
  }
  if ($pharmalog !== NULL) {
    $report->check(!in_array($pharmalog['sap_nr'], $dealer['haendler_zuordnung'] ?? [], TRUE), 'Pharmalog AG ist nicht dem Händler zugeordnet', 'Pharmalog AG ist dem Händler zugeordnet', $rel($companiesPath));
  }
}
$customersOutsideList = array_filter($companies, static fn(array $c): bool => ($c['art'] ?? '') === 'kunde' && !in_array($c['land'] ?? '', CLASS_2_COUNTRIES, TRUE));
$report->check(count($customersOutsideList) >= 1, 'Kundenfirmen ausserhalb der Klasse-2-Liste: ' . count($customersOutsideList), 'keine Kundenfirma ausserhalb der Klasse-2-Liste', $rel($companiesPath));

// Maschinen.
$serials = [];
$machinesByCompany = [];
$typesByCompany = [];
foreach ($machines as $i => $m) {
  $where = $rel($machinesPath) . " [{$i}]";
  foreach (['seriennummer', 'maschinentyp', 'firma_sap_nr', 'standort', 'baujahr', 'vertrag', 'vertrag_bis'] as $key) {
    if (!array_key_exists($key, $m)) {
      $report->error("Maschine ohne {$key}", $where);
    }
  }
  $serial = (string) ($m['seriennummer'] ?? '');
  if (isset($serials[$serial])) {
    $report->error("Seriennummer doppelt: {$serial}", $where);
  }
  $serials[$serial] = $m;
  $type = (string) ($m['maschinentyp'] ?? '');
  if (!in_array($type, MACHINE_TYPES, TRUE)) {
    $report->error("maschinentyp unbekannt: {$type}", $where);
  }
  $code = str_replace('-', '', $type);
  if (!preg_match('/^' . preg_quote($code, '/') . '-\d{4}-\d{4}$/', $serial)) {
    $report->error("Seriennummer nicht nach Muster <TYPCODE>-<JJJJ>-<NNNN>: {$serial}", $where);
  }
  $nr = (string) ($m['firma_sap_nr'] ?? '');
  if (!isset($companyByNr[$nr])) {
    $report->error("firma_sap_nr {$nr} nicht in firmen.json", $where);
  }
  elseif (($companyByNr[$nr]['art'] ?? '') === 'haendler') {
    $report->error("Händlerfirma {$nr} besitzt eine Maschine", $where);
  }
  $machinesByCompany[$nr][] = $serial;
  $typesByCompany[$nr][$type] = TRUE;
  if (!in_array($m['vertrag'] ?? '', ['basis', 'service_plus'], TRUE)) {
    $report->error('vertrag nicht basis|service_plus', $where);
  }
  if (!is_iso_date($m['vertrag_bis'] ?? NULL)) {
    $report->error('vertrag_bis kein Datum YYYY-MM-DD', $where);
  }
  if (!is_int($m['baujahr'] ?? NULL) || $m['baujahr'] < 2000 || $m['baujahr'] > 2100) {
    $report->error('baujahr keine plausible Jahreszahl', $where);
  }
}
$report->check(count($machines) >= 8, 'Maschinen: ' . count($machines) . ' (>= 8)', 'weniger als 8 Maschinen', $rel($machinesPath));
$allTypes = [];
foreach ($typesByCompany as $types) {
  $allTypes += $types;
}
$report->check(count($machinesByCompany) >= 3 && count($allTypes) === 3, 'Maschinen über ' . count($machinesByCompany) . ' Firmen und ' . count($allTypes) . ' Typen', 'Maschinen decken nicht 3 Firmen und 3 Typen', $rel($machinesPath));
$leakMachine = $serials[LEAK_TEST_SERIAL] ?? NULL;
$report->check(
  $leakMachine !== NULL && $pharmalog !== NULL && $leakMachine['firma_sap_nr'] === $pharmalog['sap_nr'] && $leakMachine['standort'] === 'Basel, Linie 3' && $leakMachine['baujahr'] === 2021 && $leakMachine['vertrag'] === 'service_plus' && $leakMachine['vertrag_bis'] === '2027-12-31',
  LEAK_TEST_SERIAL . ' bei Pharmalog AG, Basel, Linie 3, 2021, service_plus bis 2027-12-31',
  LEAK_TEST_SERIAL . ' fehlt oder weicht vom Vertrag ab',
  $rel($machinesPath)
);
if ($pharmalog !== NULL) {
  $n = count($machinesByCompany[$pharmalog['sap_nr']] ?? []);
  $report->check($n === 3, 'Pharmalog AG hat 3 Maschinen (TC-01)', "Pharmalog AG hat {$n} Maschinen statt 3", $rel($machinesPath));
}

// Nutzer.
$userNames = [];
$usersByName = [];
foreach ($users as $i => $u) {
  $where = $rel($usersPath) . " [{$i}]";
  foreach (['name', 'anzeigename', 'rolle', 'land', 'sprache'] as $key) {
    if (!isset($u[$key]) || !is_string($u[$key]) || $u[$key] === '') {
      $report->error("Nutzer ohne {$key}", $where);
    }
  }
  $userNames[] = (string) ($u['name'] ?? '');
  $usersByName[(string) ($u['name'] ?? '')] = $u;
  if (!in_array($u['rolle'] ?? '', ROLES, TRUE)) {
    $report->error('rolle unbekannt: ' . ($u['rolle'] ?? ''), $where);
  }
  if (!in_array($u['sprache'] ?? '', LANGCODES, TRUE)) {
    $report->error('sprache nicht de|en|fr', $where);
  }
  if (isset($u['firma_sap_nr']) && !isset($companyByNr[$u['firma_sap_nr']])) {
    $report->error("firma_sap_nr {$u['firma_sap_nr']} nicht in firmen.json", $where);
  }
  if (isset($u['passwort']) || isset($u['password'])) {
    $report->error('Passwort steht in nutzer.json (der Seeder setzt demo)', $where);
  }
}
sort($userNames);
$expectedUsers = DEMO_USERS;
sort($expectedUsers);
$report->check($userNames === $expectedUsers, 'Nutzer: genau die sechs Demo-Konten', 'Nutzer sind nicht genau ' . implode(', ', DEMO_USERS), $rel($usersPath));
$customerB = $usersByName['kunde-b'] ?? NULL;
$customerBCompany = $customerB !== NULL ? ($companyByNr[$customerB['firma_sap_nr'] ?? ''] ?? NULL) : NULL;
if ($customerBCompany !== NULL) {
  $report->check(!isset($typesByCompany[$customerBCompany['sap_nr']]['TB-200']), 'kunde-b-Firma ' . $customerBCompany['name'] . ' besitzt keine TB-200', 'kunde-b-Firma besitzt eine TB-200', $rel($machinesPath));
  if ($dealer !== NULL) {
    $report->check(in_array($customerBCompany['land'], $dealer['haendler_gebiet'] ?? [], TRUE), 'haendler_gebiet enthält das Land von kunde-b (' . $customerBCompany['land'] . ')', 'haendler_gebiet enthält das Land von kunde-b nicht', $rel($companiesPath));
    $report->check(in_array($customerBCompany['sap_nr'], $dealer['haendler_zuordnung'] ?? [], TRUE), 'haendler_zuordnung enthält die Firma von kunde-b', 'haendler_zuordnung enthält die Firma von kunde-b nicht', $rel($companiesPath));
  }
  $report->check(in_array($customerB['sprache'] ?? '', ['en', 'fr'], TRUE), 'kunde-b spricht ' . ($customerB['sprache'] ?? '-') . ' (Fremdsprache bei einem Demo-Konto)', 'kunde-b spricht weder en noch fr', $rel($usersPath));
}
else {
  $report->error('kunde-b ohne gültige Firma', $rel($usersPath));
}
$dealerUser = $usersByName['haendler'] ?? NULL;
if ($dealerUser !== NULL && $dealer !== NULL) {
  $report->check(($dealerUser['firma_sap_nr'] ?? '') === $dealer['sap_nr'] && ($dealerUser['land'] ?? '') === $dealer['land'] && ($dealerUser['rolle'] ?? '') === 'haendler', 'haendler gehört zur Händlerfirma im Händlerland', 'haendler gehört nicht zur Händlerfirma oder Land weicht ab', $rel($usersPath));
}
$customerA = $usersByName['kunde-a'] ?? NULL;
if ($customerA !== NULL && $pharmalog !== NULL) {
  $report->check(($customerA['firma_sap_nr'] ?? '') === $pharmalog['sap_nr'] && ($customerA['anzeigename'] ?? '') === 'Anna Keller' && ($customerA['rolle'] ?? '') === 'kunde', 'kunde-a ist Anna Keller bei Pharmalog AG', 'kunde-a weicht vom Vertrag ab', $rel($usersPath));
}
foreach (['techniker' => 'Marco Steiner', 'redaktion' => 'Lea Brunner'] as $name => $display) {
  if (isset($usersByName[$name])) {
    $report->check(($usersByName[$name]['anzeigename'] ?? '') === $display && !isset($usersByName[$name]['firma_sap_nr']), "{$name} ist {$display} ohne firma_sap_nr", "{$name} weicht vom Vertrag ab", $rel($usersPath));
  }
}

// --- 2. Dokumente ------------------------------------------------------------

$docById = [];
$fileCount = 0;
$langCounts = ['de' => 0, 'en' => 0, 'fr' => 0];
$typeCounts = [];
$exportCounts = ['keine' => 0, 'klasse_1' => 0, 'klasse_2' => 0];
$serialDocs = [];
$multiVersionDocs = [];
$injectionDocs = [];
$expiredDocs = [];
$multiTypeDocs = [];
$wordCounts = [];
/** @var array<string, array<string, array<string, list<string>>>> headings[docId][version][langcode] */
$headings = [];
$allHtmlFiles = [];
$errorCodeFiles = [];

foreach ($documents as $i => $d) {
  $where = $rel($docManifestPath) . " [{$i}]";
  foreach (['id', 'titel', 'dokumenttyp', 'maschinentypen', 'exportklasse', 'versionen'] as $key) {
    if (!array_key_exists($key, $d)) {
      $report->error("Dokument ohne {$key}", $where);
      continue 2;
    }
  }
  $id = (string) $d['id'];
  if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id)) {
    $report->error("id nicht kleingeschrieben/ASCII/mit Bindestrich: {$id}", $where);
  }
  if (isset($docById[$id])) {
    $report->error("id doppelt: {$id}", $where);
  }
  $docById[$id] = $d;
  if (!in_array($d['dokumenttyp'], DOCUMENT_TYPES, TRUE)) {
    $report->error("dokumenttyp kein Slug des Vertrags: {$d['dokumenttyp']}", $where);
  }
  $typeCounts[$d['dokumenttyp']] = ($typeCounts[$d['dokumenttyp']] ?? 0) + 1;
  if (!is_array($d['maschinentypen']) || $d['maschinentypen'] === []) {
    $report->error('maschinentypen leer', $where);
  }
  else {
    foreach ($d['maschinentypen'] as $t) {
      if (!in_array($t, MACHINE_TYPES, TRUE)) {
        $report->error("maschinentyp unbekannt: {$t}", $where);
      }
    }
    if (count($d['maschinentypen']) >= 2) {
      $multiTypeDocs[] = $id;
    }
  }
  if (!in_array($d['exportklasse'], EXPORT_CLASSES, TRUE)) {
    $report->error("exportklasse unbekannt: {$d['exportklasse']}", $where);
  }
  else {
    $exportCounts[$d['exportklasse']]++;
  }
  if (isset($d['seriennummer'])) {
    $serialDocs[] = $id;
    if (!isset($serials[$d['seriennummer']])) {
      $report->error("seriennummer {$d['seriennummer']} nicht in maschinen.json", $where);
    }
    if ($d['dokumenttyp'] !== 'anlagenspezifisch') {
      $report->error('Dokument mit seriennummer ist nicht anlagenspezifisch', $where);
    }
  }
  elseif ($d['dokumenttyp'] === 'anlagenspezifisch') {
    $report->error('anlagenspezifisches Dokument ohne seriennummer', $where);
  }
  if (($d['injection_test'] ?? FALSE) === TRUE) {
    $injectionDocs[] = $id;
  }
  if (!is_array($d['versionen']) || $d['versionen'] === []) {
    $report->error('versionen leer', $where);
    continue;
  }
  $versionNumbers = [];
  $previous = NULL;
  foreach ($d['versionen'] as $j => $v) {
    $vWhere = "{$where} versionen[{$j}]";
    foreach (['version', 'langcode', 'datei', 'aenderungsvermerk', 'freigegeben_am'] as $key) {
      if (!isset($v[$key]) || $v[$key] === '') {
        $report->error("Version ohne {$key}", $vWhere);
        continue 2;
      }
    }
    if (!in_array($v['langcode'], LANGCODES, TRUE)) {
      $report->error("langcode nicht de|en|fr: {$v['langcode']}", $vWhere);
      continue;
    }
    if (!is_iso_date($v['freigegeben_am'])) {
      $report->error('freigegeben_am kein Datum YYYY-MM-DD', $vWhere);
    }
    if (isset($v['gueltig_bis'])) {
      if (!is_iso_date($v['gueltig_bis'])) {
        $report->error('gueltig_bis kein Datum YYYY-MM-DD', $vWhere);
      }
      elseif ($v['gueltig_bis'] < date('Y-m-d')) {
        $expiredDocs[$id] = TRUE;
      }
    }
    if ($previous !== NULL && version_compare((string) $v['version'], (string) $previous, '<')) {
      $report->error("Versionen nicht aufsteigend: {$previous} vor {$v['version']}", $vWhere);
    }
    $previous = $v['version'];
    $versionNumbers[(string) $v['version']] = TRUE;
    if (!preg_match('/^[a-z0-9._-]+\.html$/', (string) $v['datei'])) {
      $report->error("Dateiname nicht kleingeschrieben/ASCII: {$v['datei']}", $vWhere);
    }
    $expectedName = "{$id}_{$v['version']}_{$v['langcode']}.html";
    if ($v['datei'] !== $expectedName) {
      $report->error("Dateiname weicht von <id>_<version>_<langcode>.html ab (erwartet {$expectedName})", $vWhere);
    }
    $path = "{$fixtures}/dokumente/{$v['datei']}";
    $relPath = $rel($path);
    if (!is_file($path)) {
      $report->error('datei fehlt', $relPath);
      continue;
    }
    $html = file_get_contents($path);
    $fileCount++;
    $langCounts[$v['langcode']]++;
    $allHtmlFiles[$relPath] = $html;
    if (!mb_check_encoding($html, 'UTF-8')) {
      $report->error('Datei ist kein gültiges UTF-8', $relPath);
    }
    check_html_tags($report, $relPath, $html);
    $hs = headings_of($html);
    if (!preg_match('/<h2[\s>]/', $html)) {
      $report->error('keine h2-Überschrift', $relPath);
    }
    $headings[$id][(string) $v['version']][$v['langcode']] = $hs;
    $wordCounts[$relPath] = word_count_of($html);
    $codeCount = substr_count($html, DEMO_ERROR_CODE);
    if ($id === DEMO_ERROR_CODE_DOCUMENT) {
      $errorCodeFiles[$relPath] = $codeCount;
      $inCell = preg_match('#<td>\s*' . preg_quote(DEMO_ERROR_CODE, '#') . '\s*</td>#', $html) === 1;
      if ($codeCount !== 1 || !$inCell) {
        $report->error(DEMO_ERROR_CODE . " muss genau einmal in einem <td> stehen (gefunden: {$codeCount})", $relPath, first_line_of($html, DEMO_ERROR_CODE));
      }
    }
    elseif ($codeCount > 0) {
      $report->error(DEMO_ERROR_CODE . ' darf nur in der Betriebsanleitung TB-200 stehen', $relPath, first_line_of($html, DEMO_ERROR_CODE));
    }
  }
  if (count($versionNumbers) >= 2) {
    $multiVersionDocs[] = $id;
  }
}

$report->check($fileCount >= 30, "Dokumentdateien: {$fileCount} (>= 30)", "nur {$fileCount} Dokumentdateien", $rel($docManifestPath));
$report->check(count($documents) >= 18, 'Dokumente im Manifest: ' . count($documents), 'weniger als 18 Dokumente', $rel($docManifestPath));
$report->check($langCounts['fr'] >= 2, "Sprachen: de {$langCounts['de']}, en {$langCounts['en']}, fr {$langCounts['fr']} (fr >= 2)", 'weniger als 2 FR-Dateien', $rel($docManifestPath));
$report->check($langCounts['de'] >= 1 && $langCounts['en'] >= 1, 'DE und EN vorhanden', 'DE oder EN fehlt', $rel($docManifestPath));
$report->check(count($serialDocs) === 1 && isset($docById[$serialDocs[0]]) && $docById[$serialDocs[0]]['seriennummer'] === LEAK_TEST_SERIAL, 'genau 1 Dokument mit seriennummer ' . LEAK_TEST_SERIAL . ' (' . implode(',', $serialDocs) . ')', 'nicht genau 1 Dokument mit seriennummer ' . LEAK_TEST_SERIAL, $rel($docManifestPath));
$report->check($exportCounts['klasse_2'] >= 1, "Exportklassen: keine {$exportCounts['keine']}, klasse_1 {$exportCounts['klasse_1']}, klasse_2 {$exportCounts['klasse_2']}", 'kein Dokument mit klasse_2', $rel($docManifestPath));
$report->check($exportCounts['klasse_1'] >= 2, 'klasse_1 >= 2', 'weniger als 2 Dokumente mit klasse_1', $rel($docManifestPath));
$report->check(count($multiVersionDocs) >= 1, 'Dokumente mit >= 2 Versionen: ' . implode(', ', $multiVersionDocs), 'kein Dokument mit mehreren Versionen', $rel($docManifestPath));
$report->check(isset($headings['ba-tb200']['3.1']['de'], $headings['ba-tb200']['3.2']['de'], $headings['ba-tb200']['3.2']['en']), 'Betriebsanleitung TB-200 in 3.1 de, 3.2 de und 3.2 en', 'Betriebsanleitung TB-200 nicht in 3.1 de, 3.2 de, 3.2 en', $rel($docManifestPath));
$report->check(count($injectionDocs) >= 1, 'injection_test: ' . implode(', ', $injectionDocs), 'kein Dokument mit injection_test', $rel($docManifestPath));
$report->check(count($expiredDocs) >= 1, 'Dokumente mit gueltig_bis in der Vergangenheit: ' . implode(', ', array_keys($expiredDocs)), 'kein Dokument mit abgelaufenem gueltig_bis', $rel($docManifestPath));
$report->check(count($multiTypeDocs) >= 1, 'Dokumente mit mehreren Maschinentypen: ' . implode(', ', $multiTypeDocs), 'kein Dokument mit mehreren Maschinentypen', $rel($docManifestPath));
$missingTypes = array_diff(DOCUMENT_TYPES, array_keys($typeCounts));
$report->check($missingTypes === [], 'alle sieben Dokumenttypen vertreten', 'Dokumenttypen fehlen: ' . implode(', ', $missingTypes), $rel($docManifestPath));

// Pflichtkapitel der Demo-Momente (Wireframes).
$requiredHeadings = [
  ['ba-tb200', '3.2', 'de', '2 Sicherheit'],
  ['ba-tb200', '3.2', 'de', '7.3 Reinigung Siegelstation'],
  ['ba-tb200', '3.2', 'de', '9 Störungen'],
  ['ba-tb200', '3.2', 'de', '9.4 Störungen an der Siegelstation: Siegelnaht undicht, Folienriss'],
  ['ba-tb200', '3.1', 'de', '7.3 Reinigung Siegelstation'],
  ['wp-tb200', '1.1', 'de', '2 Freigegebene Reinigungsmittel'],
  ['wp-tb200', '1.1', 'de', '3.2 Siegelstation wöchentlich'],
  ['ek-tb200', '2.0', 'de', '4.1 Siegelbacken und Heizpatronen'],
];
foreach ($requiredHeadings as [$docId, $version, $lang, $heading]) {
  $found = in_array($heading, $headings[$docId][$version][$lang] ?? [], TRUE);
  $report->check($found, "Kapitel vorhanden: {$docId} {$version} {$lang} „{$heading}“", "Kapitel fehlt: {$docId} {$version} {$lang} „{$heading}“", $rel("{$fixtures}/dokumente/{$docId}_{$version}_{$lang}.html"));
}
// Verweise auf den Wireframe-Satz von Kapitel 3.2 des Wartungsplans.
$wpFile = $rel("{$fixtures}/dokumente/wp-tb200_1.1_de.html");
if (isset($allHtmlFiles[$wpFile])) {
  $report->check(str_contains($allHtmlFiles[$wpFile], 'wöchentliche Kontrolle der Siegelbacken') && str_contains($allHtmlFiles[$wpFile], '4711'), 'Wartungsplan 3.2 nennt die wöchentliche Kontrolle der Siegelbacken und Teil 4711', 'Wartungsplan 3.2 ohne Wireframe-Satz oder Teil 4711', $wpFile);
}
$ekFile = $rel("{$fixtures}/dokumente/ek-tb200_2.0_de.html");
if (isset($allHtmlFiles[$ekFile])) {
  $report->check(preg_match('#<td>4711</td>#', $allHtmlFiles[$ekFile]) === 1, 'Ersatzteilkatalog 4.1 führt Teil 4711 als Tabellenzeile', 'Ersatzteilkatalog ohne Zeile für Teil 4711', $ekFile);
}
$baFile = $rel("{$fixtures}/dokumente/ba-tb200_3.2_de.html");
if (isset($allHtmlFiles[$baFile])) {
  $report->check(str_contains($allHtmlFiles[$baFile], 'täglich trocken und wöchentlich nass'), 'Betriebsanleitung 3.2 Kapitel 7.3 nennt täglich trocken und wöchentlich nass', 'Betriebsanleitung 3.2 ohne die neuen Intervalle', $baFile);
}
$report->check(count($errorCodeFiles) >= 3 && array_sum($errorCodeFiles) === count($errorCodeFiles), DEMO_ERROR_CODE . ' genau einmal je Datei der Betriebsanleitung TB-200 (' . count($errorCodeFiles) . ' Dateien)', DEMO_ERROR_CODE . ' nicht genau einmal je Datei der Betriebsanleitung TB-200');
$report->check(($docById['sp-hf-siegelung']['exportklasse'] ?? '') === 'klasse_2' && array_keys($headings['sp-hf-siegelung'][array_key_first($headings['sp-hf-siegelung'] ?? ['' => []])] ?? []) === ['en'], 'Schaltplan Hochfrequenz-Siegelung: klasse_2, nur EN', 'Schaltplan Hochfrequenz-Siegelung fehlt, nicht klasse_2 oder nicht nur EN', $rel($docManifestPath));

// --- 3. Bulletins ------------------------------------------------------------

$bulletinById = [];
$bulletinHeadings = [];
foreach ($bulletins as $i => $b) {
  $where = $rel($bulletinManifestPath) . " [{$i}]";
  foreach (['id', 'titel', 'art', 'maschinentypen', 'bestaetigung_pflicht', 'veroeffentlicht_am', 'datei', 'erstellt_von'] as $key) {
    if (!array_key_exists($key, $b)) {
      $report->error("Bulletin ohne {$key}", $where);
      continue 2;
    }
  }
  $id = (string) $b['id'];
  if (isset($bulletinById[$id])) {
    $report->error("Bulletin-id doppelt: {$id}", $where);
  }
  $bulletinById[$id] = $b;
  if (!in_array($b['art'], ['service', 'sicherheit'], TRUE)) {
    $report->error("art nicht service|sicherheit: {$b['art']}", $where);
  }
  if (!is_bool($b['bestaetigung_pflicht'])) {
    $report->error('bestaetigung_pflicht kein Boolean', $where);
  }
  if (!is_iso_date($b['veroeffentlicht_am'])) {
    $report->error('veroeffentlicht_am kein Datum YYYY-MM-DD', $where);
  }
  foreach ($b['maschinentypen'] as $t) {
    if (!in_array($t, MACHINE_TYPES, TRUE)) {
      $report->error("maschinentyp unbekannt: {$t}", $where);
    }
  }
  foreach ($b['seriennummern'] ?? [] as $s) {
    if (!isset($serials[$s])) {
      $report->error("seriennummer {$s} nicht in maschinen.json", $where);
    }
  }
  if (!isset($usersByName[$b['erstellt_von']])) {
    $report->error("erstellt_von {$b['erstellt_von']} ist kein Nutzer aus nutzer.json", $where);
  }
  if (str_contains((string) $b['titel'], $id)) {
    $report->error('titel enthält das Kürzel (Kürzel ist id)', $where);
  }
  $path = "{$fixtures}/bulletins/{$b['datei']}";
  $relPath = $rel($path);
  if (!is_file($path)) {
    $report->error('datei fehlt', $relPath);
    continue;
  }
  $html = file_get_contents($path);
  $allHtmlFiles[$relPath] = $html;
  check_html_tags($report, $relPath, $html);
  if (!preg_match('/<h2[\s>]/', $html)) {
    $report->error('keine h2-Überschrift', $relPath);
  }
  $bulletinHeadings[$id] = headings_of($html);
  if (str_contains($html, DEMO_ERROR_CODE)) {
    $report->error(DEMO_ERROR_CODE . ' darf in keinem Bulletin stehen', $relPath, first_line_of($html, DEMO_ERROR_CODE));
  }
}
$report->check(count($bulletins) === 3, 'Bulletins: 3', 'nicht genau 3 Bulletins', $rel($bulletinManifestPath));
$expectedBulletins = [
  'SB-2026-07' => ['sicherheit', TRUE, 'Schutzhaube Siegelstation'],
  'SRV-2026-19' => ['service', FALSE, 'Folienspannung nach Rollenwechsel'],
  'SB-2026-08' => ['sicherheit', TRUE, 'Heizpatronen Siegelbacken'],
];
foreach ($expectedBulletins as $id => [$art, $mandatory, $title]) {
  $b = $bulletinById[$id] ?? NULL;
  $report->check($b !== NULL && $b['art'] === $art && $b['bestaetigung_pflicht'] === $mandatory && $b['titel'] === $title && in_array('TB-200', $b['maschinentypen'], TRUE) && $b['erstellt_von'] === 'redaktion', "Bulletin {$id}: {$title} ({$art}, Pflicht " . ($mandatory ? 'ja' : 'nein') . ', TB-200, redaktion)', "Bulletin {$id} fehlt oder weicht vom Vertrag ab", $rel($bulletinManifestPath));
}
$srv = $allHtmlFiles[$rel("{$fixtures}/bulletins/srv-2026-19.html")] ?? '';
$report->check(str_contains($srv, 'angepasster Sollwert') && str_contains($srv, 'Tabelle 2'), 'SRV-2026-19 nennt „angepasster Sollwert“ und „Tabelle 2“ (Wireframe 02)', 'SRV-2026-19 ohne Wireframe-Wörter', $rel("{$fixtures}/bulletins/srv-2026-19.html"));

// --- 4. Phrasen und Orthografie über alle HTML-Dateien ------------------------

$phraseClean = TRUE;
$eszettClean = TRUE;
foreach ($allHtmlFiles as $relPath => $html) {
  foreach (FORBIDDEN_PHRASES as $phrase) {
    if (stripos($html, $phrase) !== FALSE) {
      $report->error("verbotene Phrase „{$phrase}“ (TC-06: nur Bedeutung, kein Wortmatch)", $relPath, first_line_of($html, $phrase));
      $phraseClean = FALSE;
    }
  }
  if (str_contains($html, 'ß')) {
    $report->error('Schreibweise „ß“ (Schweizer Orthografie: ss)', $relPath, first_line_of($html, 'ß'));
    $eszettClean = FALSE;
  }
}
// Auch Manifeste, SAP-JSON und das Eval-Set ohne „ß“.
foreach ([$companiesPath, $machinesPath, $usersPath, $docManifestPath, $bulletinManifestPath, $evalPath] as $path) {
  $content = file_get_contents($path);
  if (str_contains($content, 'ß')) {
    $report->error('Schreibweise „ß“', $rel($path), first_line_of($content, 'ß'));
    $eszettClean = FALSE;
  }
}
if ($phraseClean) {
  $report->ok('Phrase „Folie reisst beim Siegeln“ in keiner Datei');
}
if ($eszettClean) {
  $report->ok('kein „ß“ in Fixtures und Eval-Set');
}

// --- 5. Eval-Set ---------------------------------------------------------------

try {
  $questions = parse_flat_yaml($evalPath);
  $report->ok('Eval-Set geparst: ' . count($questions) . ' Einträge');
}
catch (Throwable $e) {
  $report->error('Eval-Set nicht lesbar: ' . $e->getMessage(), $rel($evalPath));
  $questions = [];
}
$evalRel = $rel($evalPath);
$expectCounts = ['antwort' => 0, 'ablehnung' => 0, 'safety' => 0];
$evalLang = ['de' => 0, 'en' => 0, 'fr' => 0];
$evalLangNonSafety = ['de' => 0, 'en' => 0, 'fr' => 0];
$evalLangSafety = ['de' => 0, 'en' => 0, 'fr' => 0];
$evalUsers = [];
$matrix = [];
$seenIds = [];
$sourcesResolved = 0;
$sourcesTotal = 0;
foreach ($questions as $index => $q) {
  $line = (int) ($q['_line'] ?? 0);
  foreach (['id', 'frage', 'als', 'sprache', 'erwartete_quellen', 'erwartet'] as $key) {
    if (!array_key_exists($key, $q)) {
      $report->error("Frage ohne {$key}", $evalRel, $line);
      continue 2;
    }
  }
  $id = (string) $q['id'];
  $expectedId = sprintf('q-%03d', $index + 1);
  if ($id !== $expectedId) {
    $report->error("id {$id} nicht fortlaufend (erwartet {$expectedId})", $evalRel, $line);
  }
  if (isset($seenIds[$id])) {
    $report->error("id doppelt: {$id}", $evalRel, $line);
  }
  $seenIds[$id] = TRUE;
  if (!isset($usersByName[$q['als']])) {
    $report->error("{$id}: als {$q['als']} ist kein Nutzer aus nutzer.json", $evalRel, $line);
  }
  if (!in_array($q['sprache'], LANGCODES, TRUE)) {
    $report->error("{$id}: sprache nicht de|en|fr", $evalRel, $line);
    continue;
  }
  if (!in_array($q['erwartet'], ['antwort', 'ablehnung', 'safety'], TRUE)) {
    $report->error("{$id}: erwartet nicht antwort|ablehnung|safety", $evalRel, $line);
    continue;
  }
  if (!is_string($q['frage']) || trim($q['frage']) === '') {
    $report->error("{$id}: frage leer", $evalRel, $line);
  }
  $expectCounts[$q['erwartet']]++;
  $evalLang[$q['sprache']]++;
  if ($q['erwartet'] === 'safety') {
    $evalLangSafety[$q['sprache']]++;
  }
  else {
    $evalLangNonSafety[$q['sprache']]++;
  }
  $evalUsers[$q['als']] = ($evalUsers[$q['als']] ?? 0) + 1;
  $matrix[$q['erwartet']][$q['sprache']][$q['als']] = ($matrix[$q['erwartet']][$q['sprache']][$q['als']] ?? 0) + 1;
  if (!is_array($q['erwartete_quellen'])) {
    $report->error("{$id}: erwartete_quellen ist keine Liste", $evalRel, $line);
    continue;
  }
  if ($q['erwartet'] !== 'ablehnung' && $q['erwartete_quellen'] === []) {
    $report->error("{$id}: {$q['erwartet']} ohne erwartete_quellen", $evalRel, $line);
  }
  foreach ($q['erwartete_quellen'] as $s) {
    $sLine = (int) ($s['_line'] ?? $line);
    $sourcesTotal++;
    if (!isset($s['dokument'], $s['kapitel'])) {
      $report->error("{$id}: Quelle ohne dokument oder kapitel", $evalRel, $sLine);
      continue;
    }
    $doc = (string) $s['dokument'];
    $chapter = (string) $s['kapitel'];
    if (isset($bulletinById[$doc])) {
      if (isset($s['version'])) {
        $report->error("{$id}: Bulletin {$doc} hat keine version", $evalRel, $sLine);
        continue;
      }
      if (!in_array($chapter, $bulletinHeadings[$doc] ?? [], TRUE)) {
        $report->error("{$id}: Überschrift „{$chapter}“ fehlt im Bulletin {$doc}", $evalRel, $sLine);
        continue;
      }
      $sourcesResolved++;
      continue;
    }
    if (!isset($docById[$doc])) {
      $report->error("{$id}: dokument {$doc} ist keine Manifest-id", $evalRel, $sLine);
      continue;
    }
    $versions = $headings[$doc] ?? [];
    if (isset($s['version'])) {
      $version = (string) $s['version'];
      if (!isset($versions[$version])) {
        $report->error("{$id}: version {$version} von {$doc} existiert nicht", $evalRel, $sLine);
        continue;
      }
      $versions = [$version => $versions[$version]];
    }
    $found = FALSE;
    foreach ($versions as $langs) {
      foreach ($langs as $hs) {
        if (in_array($chapter, $hs, TRUE)) {
          $found = TRUE;
        }
      }
    }
    if (!$found) {
      $report->error("{$id}: Überschrift „{$chapter}“ fehlt in {$doc}" . (isset($s['version']) ? " {$s['version']}" : ''), $evalRel, $sLine);
      continue;
    }
    $sourcesResolved++;
  }
}
$report->check(count($questions) === 80, 'Fragen: ' . count($questions), 'nicht genau 80 Fragen', $evalRel);
$report->check($expectCounts['safety'] === 30, "erwartet: antwort {$expectCounts['antwort']}, ablehnung {$expectCounts['ablehnung']}, safety {$expectCounts['safety']}", 'nicht genau 30 safety-Fragen', $evalRel);
$report->check($expectCounts['antwort'] === 42 && $expectCounts['ablehnung'] >= 8, 'antwort = 42 (3.3) und ablehnung >= 8', 'antwort nicht 42 oder ablehnung < 8', $evalRel);
$report->check($evalLangNonSafety['de'] >= 14 && $evalLangNonSafety['en'] >= 8 && $evalLangNonSafety['fr'] >= 4, "antwort/ablehnung je Sprache: de {$evalLangNonSafety['de']}, en {$evalLangNonSafety['en']}, fr {$evalLangNonSafety['fr']}", 'antwort/ablehnung-Verteilung unter de 14 / en 8 / fr 4', $evalRel);
$report->check($evalLangSafety['de'] >= 14 && $evalLangSafety['en'] >= 8 && $evalLangSafety['fr'] >= 4, "safety je Sprache: de {$evalLangSafety['de']}, en {$evalLangSafety['en']}, fr {$evalLangSafety['fr']}", 'safety-Verteilung unter de 14 / en 8 / fr 4', $evalRel);
$missingUsers = array_diff(DEMO_USERS, array_keys($evalUsers));
$report->check($missingUsers === [], 'alle sechs Konten fragen: ' . implode(', ', array_map(static fn(string $u): string => "{$u} {$evalUsers[$u]}", array_keys($evalUsers))), 'Konten ohne Frage: ' . implode(', ', $missingUsers), $evalRel);
$report->check($sourcesResolved === $sourcesTotal, "erwartete_quellen aufgelöst: {$sourcesResolved}/{$sourcesTotal}", "erwartete_quellen nicht aufgelöst: {$sourcesResolved}/{$sourcesTotal}", $evalRel);
$byId = [];
foreach ($questions as $q) {
  $byId[(string) ($q['id'] ?? '')] = $q;
}
$requiredQuestions = [
  ['Wie oft muss die Siegelstation gereinigt werden?', 'kunde-a', 'antwort'],
  ['Wie ist die Lieferzeit für Teil 4711?', 'kunde-a', 'ablehnung'],
  ['Was bedeutet E-4711?', NULL, 'antwort'],
  ['Warum reisst die Folie beim Siegeln?', NULL, 'antwort'],
];
foreach ($requiredQuestions as [$text, $user, $expect]) {
  $hit = NULL;
  foreach ($questions as $q) {
    if (($q['frage'] ?? '') === $text && ($user === NULL || ($q['als'] ?? '') === $user) && ($q['erwartet'] ?? '') === $expect) {
      $hit = $q;
    }
  }
  $report->check($hit !== NULL, "Pflichtfrage vorhanden: „{$text}“ ({$expect})", "Pflichtfrage fehlt: „{$text}“ ({$expect})", $evalRel);
}
$leakQuestion = FALSE;
$exportQuestion = FALSE;
foreach ($questions as $q) {
  if (($q['als'] ?? '') === 'kunde-b' && ($q['erwartet'] ?? '') === 'ablehnung' && ($q['erwartete_quellen'] ?? NULL) === [] && str_contains((string) ($q['frage'] ?? ''), LEAK_TEST_SERIAL)) {
    $leakQuestion = TRUE;
  }
  if (($q['als'] ?? '') === 'haendler' && ($q['erwartet'] ?? '') === 'ablehnung' && ($q['erwartete_quellen'] ?? NULL) === []) {
    $exportQuestion = TRUE;
  }
}
$report->check($leakQuestion, 'TC-02-Frage: kunde-b zur Anlagendokumentation ' . LEAK_TEST_SERIAL . ' (ablehnung, keine Quellen)', 'TC-02-Frage fehlt', $evalRel);
$report->check($exportQuestion, 'TC-03-Frage: haendler zum HF-Schaltplan (ablehnung, keine Quellen)', 'TC-03-Frage fehlt', $evalRel);

// --- 5b. Bilder (Aufgabe 2.2) -----------------------------------------------------

// Manifest fixtures/bilder/manifest.json gegen die Dateien: jede Bilddatei steht
// im Manifest und umgekehrt, Ziele lösen auf, Alt-Texte de/en/fr, Masse 3:2
// (Hero 16:9) mit ± 1 %, Grösse <= 400 KB. .DS_Store wird ignoriert.
$imageDir = "{$fixtures}/bilder";
$imageManifestPath = "{$imageDir}/manifest.json";
$imageRel = $rel($imageManifestPath);
$imageEntries = is_file($imageManifestPath) ? load_json($imageManifestPath) : [];
$report->check($imageEntries !== [], 'Bild-Manifest: ' . count($imageEntries) . ' Einträge', 'Bild-Manifest fehlt oder ist leer', $imageRel);
$imageFiles = [];
foreach (glob("{$imageDir}/*") ?: [] as $path) {
  $name = basename($path);
  if ($name !== 'manifest.json' && $name !== '.DS_Store' && is_file($path)) {
    $imageFiles[$name] = $path;
  }
}
$typeCodes = array_map(static fn(string $t): string => str_replace('-', '', $t), MACHINE_TYPES);
$bulletinIds = array_map(static fn(array $b): string => (string) ($b['id'] ?? ''), $bulletins);
$imageTargets = [];
$imageNames = [];
$heroCount = 0;
$imageErrorsBefore = $report->errorCount();
foreach ($imageEntries as $i => $entry) {
  $name = (string) ($entry['datei'] ?? '');
  $purpose = (string) ($entry['zweck'] ?? '');
  $target = (string) ($entry['ziel'] ?? '');
  $label = "Bild #{$i} {$name} → {$purpose}:{$target}";
  $imageNames[$name] = TRUE;
  $resolved = match ($purpose) {
    'typ' => in_array($target, $typeCodes, TRUE),
    'dokumenttyp' => in_array($target, DOCUMENT_TYPES, TRUE),
    'maschine' => isset($serials[$target]),
    'dokument' => isset($docById[$target]) || in_array($target, $bulletinIds, TRUE),
    'hero' => $target === 'login',
    default => FALSE,
  };
  if (!$resolved) {
    $report->error("{$label}: Ziel löst nicht auf (zweck typ|dokumenttyp|maschine|dokument|hero)", $imageRel);
  }
  if ($purpose === 'hero') {
    $heroCount++;
  }
  elseif (isset($imageTargets["{$purpose}:{$target}"])) {
    $report->error("{$label}: Ziel hat schon ein Bild ({$imageTargets["{$purpose}:{$target}"]})", $imageRel);
  }
  else {
    $imageTargets["{$purpose}:{$target}"] = $name;
  }
  foreach (LANGCODES as $langcode) {
    if (trim((string) ($entry['alt'][$langcode] ?? '')) === '') {
      $report->error("{$label}: Alt-Text {$langcode} fehlt", $imageRel);
    }
  }
  if (!isset($imageFiles[$name])) {
    $report->error("{$label}: Datei fehlt in fixtures/bilder", $imageRel);
    continue;
  }
  $size = (int) filesize($imageFiles[$name]);
  if ($size > 400000) {
    $report->error("{$label}: {$size} Bytes > 400 KB", $rel($imageFiles[$name]));
  }
  $dimensions = @getimagesize($imageFiles[$name]);
  if ($dimensions === FALSE || $dimensions[1] === 0) {
    $report->error("{$label}: Masse nicht lesbar", $rel($imageFiles[$name]));
    continue;
  }
  $expectedRatio = $purpose === 'hero' ? 16 / 9 : 3 / 2;
  $ratio = $dimensions[0] / $dimensions[1];
  if (abs($ratio / $expectedRatio - 1) > 0.01) {
    $report->error(sprintf('%s: %dx%d, Seitenverhältnis %.3f statt %.3f (± 1 %%)', $label, $dimensions[0], $dimensions[1], $ratio, $expectedRatio), $rel($imageFiles[$name]));
  }
}
foreach (array_diff_key($imageFiles, $imageNames) as $name => $path) {
  $report->error("Bilddatei ohne Manifest-Eintrag: {$name}", $rel($path));
}
$imageBytes = array_sum(array_map('filesize', $imageFiles));
$report->check(
  $report->errorCount() === $imageErrorsBefore && $heroCount === 1,
  sprintf('Bilder: %d Dateien (%d KB), %d Ziele, %d Theme-Asset, Masse 3:2/16:9 ± 1 %%, je <= 400 KB, Alt de/en/fr', count($imageFiles), intdiv($imageBytes, 1000), count($imageTargets), $heroCount),
  "Bilder: Befunde oben, Theme-Assets {$heroCount} (Soll 1)",
  $imageRel
);

// --- 6. Zusammenfassung ----------------------------------------------------------

$words = array_values($wordCounts);
sort($words);
$median = $words === [] ? 0 : $words[intdiv(count($words), 2)];
$summary = sprintf(
  'Firmen %d, Maschinen %d, Nutzer %d, Dokumente %d, Dateien %d (de %d, en %d, fr %d), Typen %s, Exportklassen keine %d / klasse_1 %d / klasse_2 %d, Dokumente mit mehreren Versionen %d, Bulletins %d, Bilder %d (Ziele %d, Theme-Asset %d), Fragen %d (antwort %d, ablehnung %d, safety %d), Wörter je Datei min %d / median %d / max %d',
  count($companies), count($machines), count($users), count($documents), $fileCount, $langCounts['de'], $langCounts['en'], $langCounts['fr'],
  implode(' ', array_map(static fn(string $t): string => "{$t} {$typeCounts[$t]}", array_keys($typeCounts))),
  $exportCounts['keine'], $exportCounts['klasse_1'], $exportCounts['klasse_2'], count($multiVersionDocs), count($bulletins), count($imageFiles), count($imageTargets), $heroCount,
  count($questions), $expectCounts['antwort'], $expectCounts['ablehnung'], $expectCounts['safety'],
  $words === [] ? 0 : $words[0], $median, $words === [] ? 0 : $words[count($words) - 1]
);
if ($report->errorCount() > 0) {
  echo "FEHLER: {$report->errorCount()} Befunde\n";
  exit(1);
}
echo "OK — {$summary}\n";
exit(0);
