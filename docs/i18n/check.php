<?php

declare(strict_types=1);

/**
 * Prüft die Übersetzungen je Modul/Theme gegen die extrahierten Quellstrings (Aufgabe 6.5).
 *
 * Aufruf (im Repo tannwald-portal/): php docs/i18n/check.php [-v] [de|fr ...]
 * Je Sprache: fehlend (Quelle ohne Eintrag oder Fuzzy), leer (msgstr leer), veraltet (Eintrag ohne Quelle, nur Hinweis).
 * Exit 0 bei 0 fehlenden und 0 leeren Strings, sonst 1.
 */

require __DIR__ . '/lib.php';

$verbose = in_array('-v', $argv, TRUE);
$langs = array_values(array_filter(array_slice($argv, 1), fn($a) => in_array($a, ['de', 'fr'], TRUE))) ?: ['de', 'fr'];
$bad = 0;
printf("%-20s %-4s %8s %8s %8s %8s\n", 'Einheit', 'Spr.', 'Quelle', 'fehlend', 'leer', 'veraltet');
foreach (array_keys(I18N_UNITS) as $unit) {
  $src = i18n_collect($unit)['strings'];
  foreach ($langs as $lang) {
    $po = i18n_parse_po(i18n_root() . '/' . I18N_UNITS[$unit] . "/translations/$lang.po");
    $missing = $empty = [];
    foreach ($src as $key => $s) {
      if (!isset($po[$key]) || $po[$key]['fuzzy']) {
        $missing[] = $s;
      }
      elseif (trim(implode('', $po[$key]['str'])) === '' || ($s['plural'] !== NULL && count(array_filter($po[$key]['str'], 'strlen')) < 2)) {
        $empty[] = $s;
      }
    }
    $stale = array_filter(array_diff_key($po, $src), fn($e) => empty($e['core']));
    printf("%-20s %-4s %8d %8d %8d %8d\n", $unit, $lang, count($src), count($missing), count($empty), count($stale));
    $bad += count($missing) + count($empty);
    if ($verbose) {
      foreach ([['fehlt', $missing], ['leer', $empty]] as [$label, $list]) {
        foreach ($list as $s) {
          echo "  $label [$lang] " . ($s['ctx'] !== '' ? "{{$s['ctx']}} " : '') . $s['id'] . '  (' . $s['where'][0] . ")\n";
        }
      }
      foreach ($stale as $e) {
        echo "  veraltet [$lang] " . $e['id'] . "\n";
      }
    }
  }
}
echo $bad === 0 ? "OK: 0 fehlende, 0 leere Strings.\n" : "FEHLER: $bad fehlende oder leere Strings (mit -v auflisten).\n";
exit($bad === 0 ? 0 : 1);
