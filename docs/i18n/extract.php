<?php

declare(strict_types=1);

/**
 * Extrahiert alle übersetzbaren Quellstrings der fünf Module und des Themes (Aufgabe 6.5).
 *
 * Aufruf (im Repo tannwald-portal/): php docs/i18n/extract.php [einheit]
 * Ausgabe: docs/i18n/strings/strings.<einheit>.txt (sortiert, "kontext | msgid | Fundstellen") und eine Zählung.
 * Dynamische Aufrufe (t($variable)) werden nicht extrahiert, aber gezählt und gelistet.
 */

require __DIR__ . '/lib.php';

$only = $argv[1] ?? NULL;
$outDir = __DIR__ . '/strings';
is_dir($outDir) || mkdir($outDir, 0775, TRUE);
$total = 0;
printf("%-20s %8s %8s\n", 'Einheit', 'Strings', 'dynamisch');
foreach (array_keys(I18N_UNITS) as $unit) {
  if ($only !== NULL && $only !== $unit) {
    continue;
  }
  $r = i18n_collect($unit);
  $lines = [];
  foreach ($r['strings'] as $s) {
    $lines[] = ($s['ctx'] !== '' ? $s['ctx'] : '-') . ' | ' . str_replace("\n", '\n', $s['id'])
      . ($s['plural'] !== NULL ? ' || ' . $s['plural'] : '') . ' | ' . implode(', ', array_slice($s['where'], 0, 3));
  }
  foreach (array_unique($r['dynamic']) as $d) {
    $lines[] = "# dynamisch: $d";
  }
  file_put_contents("$outDir/strings.$unit.txt", implode("\n", $lines) . "\n");
  printf("%-20s %8d %8d\n", $unit, count($r['strings']), count($r['dynamic']));
  $total += count($r['strings']);
}
echo "Summe: $total\n";
