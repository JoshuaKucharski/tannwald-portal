<?php

declare(strict_types=1);

/**
 * Gemeinsame Funktionen für extract.php und check.php (Aufgabe 6.5).
 *
 * Keine Abhängigkeiten, läuft mit dem PHP des Hosts. Schlüssel eines Quellstrings:
 * "<kontext>\x04<msgid>"; Pluralstrings tragen zusätzlich "plural" (Singular/Plural-Paar).
 */

const I18N_UNITS = [
  'tannwald_access' => 'web/modules/custom/tannwald_access',
  'tannwald_assistant' => 'web/modules/custom/tannwald_assistant',
  'tannwald_bulletin' => 'web/modules/custom/tannwald_bulletin',
  'tannwald_demo' => 'web/modules/custom/tannwald_demo',
  'tannwald_ingest' => 'web/modules/custom/tannwald_ingest',
  'tannwald' => 'web/themes/custom/tannwald',
];

/** Repo-Wurzel (tannwald-portal/), unabhängig vom Aufrufort. */
function i18n_root(): string {
  return dirname(__DIR__, 2);
}

/** Alle Dateien einer Einheit, ohne Tests, Abhängigkeiten, Build-Ausgabe, Übersetzungen. */
function i18n_files(string $dir): array {
  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $p = $f->getPathname();
    if (preg_match('#/(tests|node_modules|dist|translations|vendor)/#', $p)) {
      continue;
    }
    $out[] = $p;
  }
  sort($out);
  return $out;
}

/** Wert eines PHP-String-Tokens oder NULL (dynamisch, z. B. mit Variablen). */
function i18n_php_literal(array $tok): ?string {
  $raw = $tok[1];
  if ($raw[0] === "'") {
    return strtr(substr($raw, 1, -1), ["\\'" => "'", '\\\\' => '\\']);
  }
  $body = substr($raw, 1, -1);
  return str_contains($body, '$') ? NULL : stripcslashes($body);
}

/**
 * Extrahiert aus einer PHP-Datei. Rückgabe: Liste {id, plural, ctx, line, dynamic}.
 */
function i18n_extract_php(string $code): array {
  $toks = array_values(array_filter(token_get_all($code), fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)));
  $n = count($toks);
  $res = [];
  // Liest ab Index $i ein String-Argument (auch "a" . "b"); setzt $i hinter das Argument.
  $arg = function (int &$i) use ($toks, $n): array {
    $val = '';
    $dyn = FALSE;
    $line = $toks[$i][2] ?? 0;
    $first = TRUE;
    while ($i < $n) {
      $t = $toks[$i];
      if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
        $v = i18n_php_literal($t);
        if ($v === NULL) {
          $dyn = TRUE;
        }
        else {
          $val .= $v;
        }
      }
      else {
        $dyn = TRUE;
        break;
      }
      $i++;
      if ($i < $n && $toks[$i] === '.') {
        $i++;
        continue;
      }
      break;
    }
    return [$dyn ? NULL : $val, $line];
  };
  // Kontext aus Optionen-Array ab $i bis zur schliessenden Klammer des Aufrufs.
  $ctx = function (int $i) use ($toks, $n): string {
    $depth = 0;
    for (; $i < $n; $i++) {
      $t = $toks[$i];
      if ($t === '(' || $t === '[') {
        $depth++;
      }
      elseif ($t === ')' || $t === ']') {
        if (--$depth < 0) {
          return '';
        }
      }
      elseif (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && trim($t[1], "'\"") === 'context'
        && isset($toks[$i + 1], $toks[$i + 2]) && is_array($toks[$i + 1]) && $toks[$i + 1][0] === T_DOUBLE_ARROW
        && is_array($toks[$i + 2]) && $toks[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
        return (string) i18n_php_literal($toks[$i + 2]);
      }
    }
    return '';
  };
  for ($i = 0; $i < $n; $i++) {
    $t = $toks[$i];
    if (!is_array($t)) {
      continue;
    }
    $name = NULL;
    if ($t[0] === T_STRING && $t[1] === 't' && ($toks[$i + 1] ?? NULL) === '(') {
      $prev = $toks[$i - 1] ?? NULL;
      if (is_array($prev) && in_array($prev[0], [T_FUNCTION], TRUE)) {
        continue;
      }
      $name = 't';
      $j = $i + 2;
    }
    elseif ($t[0] === T_NEW && is_array($toks[$i + 1] ?? NULL) && in_array(ltrim($toks[$i + 1][1], '\\'), ['TranslatableMarkup', 'Drupal\\Core\\StringTranslation\\TranslatableMarkup'], TRUE) && ($toks[$i + 2] ?? NULL) === '(') {
      $name = 't';
      $j = $i + 3;
    }
    elseif ($t[0] === T_STRING && $t[1] === 'formatPlural' && ($toks[$i + 1] ?? NULL) === '(') {
      // formatPlural($count, 'singular', 'plural'): erstes Argument überspringen.
      $j = $i + 2;
      $depth = 0;
      for (; $j < $n; $j++) {
        if ($toks[$j] === '(' || $toks[$j] === '[') {
          $depth++;
        }
        elseif ($toks[$j] === ')' || $toks[$j] === ']') {
          $depth--;
        }
        elseif ($toks[$j] === ',' && $depth === 0) {
          $j++;
          break;
        }
      }
      [$s, $line] = $arg($j);
      if (($toks[$j] ?? NULL) === ',') {
        $j++;
        [$p] = $arg($j);
        $res[] = ['id' => $s, 'plural' => $p, 'ctx' => $ctx($j), 'line' => $line, 'dynamic' => $s === NULL || $p === NULL];
      }
      continue;
    }
    if ($name === NULL) {
      continue;
    }
    [$s, $line] = $arg($j);
    $res[] = ['id' => $s, 'plural' => NULL, 'ctx' => $ctx($j), 'line' => $line, 'dynamic' => $s === NULL];
  }
  return $res;
}

/** Twig: 'x'|t, "x"|t, mit optionalem {'context': 'c'}; {% trans %}…{% endtrans %}. */
function i18n_extract_twig(string $src): array {
  $res = [];
  $lineOf = fn(int $off) => substr_count(substr($src, 0, $off), "\n") + 1;
  $q = "(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")";
  if (preg_match_all('/' . $q . '\s*\|\s*t\b(\s*\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\))?/s', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($m as $x) {
      $id = $x[1][1] >= 0 ? $x[1][0] : $x[2][0];
      $id = $x[1][1] >= 0 ? strtr($id, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($id);
      $ctx = '';
      if (isset($x[4][0]) && preg_match('/[\'"]context[\'"]\s*:\s*[\'"]([^\'"]*)[\'"]/', $x[4][0], $c)) {
        $ctx = $c[1];
      }
      $res[] = ['id' => $id, 'plural' => NULL, 'ctx' => $ctx, 'line' => $lineOf($x[0][1]), 'dynamic' => FALSE];
    }
  }
  if (preg_match_all('/\{%\s*trans\s*%\}(.*?)\{%\s*endtrans\s*%\}/s', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($m as $x) {
      $body = preg_replace('/\s+/', ' ', trim($x[1][0]));
      $parts = preg_split('/\{%\s*plural\s+[^%]*%\}/', $body);
      $res[] = ['id' => trim($parts[0]), 'plural' => isset($parts[1]) ? trim($parts[1]) : NULL, 'ctx' => '', 'line' => $lineOf($x[0][1]), 'dynamic' => FALSE];
    }
  }
  return $res;
}

/** YAML-Zeilenmuster: title/description in *.links.*.yml, *.permissions.yml, _title in *.routing.yml. */
function i18n_extract_yaml(string $file, string $src): array {
  $base = basename($file);
  $keys = NULL;
  if (preg_match('/\.(links\.(menu|task|action|contextual)|permissions)\.yml$/', $base)) {
    $keys = ['title', 'description'];
  }
  elseif (preg_match('/\.routing\.yml$/', $base)) {
    $keys = ['_title'];
  }
  if ($keys === NULL) {
    return [];
  }
  $res = [];
  foreach (explode("\n", $src) as $i => $line) {
    if (preg_match('/^\s+(' . implode('|', $keys) . '):\s*(.+?)\s*$/', $line, $m)) {
      $v = $m[2];
      if ($v[0] === "'" && substr($v, -1) === "'") {
        $v = str_replace("''", "'", substr($v, 1, -1));
      }
      elseif ($v[0] === '"' && substr($v, -1) === '"') {
        $v = stripcslashes(substr($v, 1, -1));
      }
      elseif (in_array($v[0], ['>', '|'], TRUE)) {
        continue;
      }
      $res[] = ['id' => $v, 'plural' => NULL, 'ctx' => '', 'line' => $i + 1, 'dynamic' => FALSE];
    }
  }
  return $res;
}

/** Quellstrings einer Einheit: [key => {id, plural, ctx, where[]}], dazu dynamische Fundstellen. */
function i18n_collect(string $unit): array {
  $dir = i18n_root() . '/' . I18N_UNITS[$unit];
  $strings = [];
  $dynamic = [];
  foreach (i18n_files($dir) as $f) {
    $ext = pathinfo($f, PATHINFO_EXTENSION);
    $src = (string) file_get_contents($f);
    $rel = substr($f, strlen(i18n_root()) + 1);
    if (in_array($ext, ['php', 'module', 'inc', 'theme', 'install'], TRUE)) {
      $found = i18n_extract_php($src);
    }
    elseif ($ext === 'twig') {
      $found = i18n_extract_twig($src);
    }
    elseif ($ext === 'yml') {
      $found = i18n_extract_yaml($f, $src);
    }
    else {
      continue;
    }
    // Konstanten-Quellen: const SOURCE = ['key' => 'English'] und AnswerTexts::NAME['en'] (siehe i18n_const_sources()).
    $constSources = in_array($ext, ['php', 'module', 'inc', 'theme', 'install'], TRUE) ? i18n_const_sources($src, $dir) : [];
    $lines = explode("\n", $src);
    foreach ($found as $x) {
      if ($x['dynamic']) {
        $text = $lines[$x['line'] - 1] ?? '';
        if (!($constSources && preg_match('/TranslatableMarkup\(\s*(self::SOURCE\[|AnswerTexts::\w+\[\'en\'\])/', $text))) {
          $dynamic[] = "$rel:{$x['line']}";
        }
        continue;
      }
      if ($x['id'] === '') {
        continue;
      }
      $key = $x['ctx'] . "\x04" . $x['id'];
      $strings[$key] ??= ['id' => $x['id'], 'plural' => $x['plural'], 'ctx' => $x['ctx'], 'where' => []];
      $strings[$key]['where'][] = "$rel:{$x['line']}";
    }
  }
  foreach (i18n_all_const_sources($dir) as $x) {
    $key = "\x04" . $x['id'];
    $strings[$key] ??= ['id' => $x['id'], 'plural' => NULL, 'ctx' => '', 'where' => []];
    $strings[$key]['where'][] = $x['where'];
  }
  ksort($strings);
  return ['strings' => $strings, 'dynamic' => array_values(array_unique($dynamic))];
}

/** Liest ein .po. Rückgabe: [key => {id, plural, ctx, str[], fuzzy}]. */
function i18n_parse_po(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $entries = [];
  $cur = NULL;
  $field = NULL;
  $fuzzy = FALSE;
  $core = FALSE;
  $flush = function () use (&$cur, &$entries, &$core) {
    if ($cur !== NULL && $cur['id'] !== '') {
      $cur['core'] = $core;
      $core = FALSE;
      $entries[$cur['ctx'] . "\x04" . $cur['id']] = $cur;
    }
    $cur = NULL;
  };
  $unq = fn(string $s) => stripcslashes(substr(trim($s), 1, -1));
  foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    $line = rtrim($line);
    if ($line === '') {
      $flush();
      $field = NULL;
      $fuzzy = FALSE;
      continue;
    }
    if ($line[0] === '#') {
      if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
        $fuzzy = TRUE;
      }
      if ($line === '# core override') {
        $core = TRUE;
      }
      continue;
    }
    if (preg_match('/^msgctxt\s+(".*")$/', $line, $m)) {
      $flush();
      $cur = ['id' => '', 'plural' => NULL, 'ctx' => $unq($m[1]), 'str' => [''], 'fuzzy' => $fuzzy];
      $field = 'ctx';
    }
    elseif (preg_match('/^msgid\s+(".*")$/', $line, $m)) {
      if ($cur === NULL || $field !== 'ctx') {
        $flush();
        $cur = ['id' => '', 'plural' => NULL, 'ctx' => '', 'str' => [''], 'fuzzy' => $fuzzy];
      }
      $cur['id'] = $unq($m[1]);
      $field = 'id';
    }
    elseif (preg_match('/^msgid_plural\s+(".*")$/', $line, $m)) {
      $cur['plural'] = $unq($m[1]);
      $field = 'plural';
    }
    elseif (preg_match('/^msgstr(?:\[(\d+)\])?\s+(".*")$/', $line, $m)) {
      $idx = $m[1] === '' ? 0 : (int) $m[1];
      $cur['str'][$idx] = $unq($m[2]);
      $field = 'str' . $idx;
    }
    elseif ($line[0] === '"' && $cur !== NULL) {
      $v = $unq($line);
      if ($field === 'ctx') {
        $cur['ctx'] .= $v;
      }
      elseif ($field === 'id') {
        $cur['id'] .= $v;
      }
      elseif ($field === 'plural') {
        $cur['plural'] .= $v;
      }
      else {
        $cur['str'][(int) substr((string) $field, 3)] .= $v;
      }
    }
  }
  $flush();
  return $entries;
}

/** Prüft, ob eine Datei Konstanten-Quellen nutzt (self::SOURCE[...] oder AnswerTexts::X['en'] in TranslatableMarkup). */
function i18n_const_sources(string $src, string $dir): bool {
  return (bool) preg_match('/TranslatableMarkup\(\s*(self::SOURCE\[|AnswerTexts::\w+\[\'en\'\])/', $src);
}

/** Wert eines einfach gequoteten Literals ohne Verkettung. */
function i18n_unq(string $q): string {
  return strtr(substr($q, 1, -1), ["\\'" => "'", '\\\\' => '\\']);
}

/**
 * Quellstrings aus Konstanten: `const SOURCE = ['key' => 'English', …]` in Dateien, die sie über
 * TranslatableMarkup(self::SOURCE[$key]) übersetzen, und die en-Fassung der AnswerTexts-Konstanten,
 * die per TranslatableMarkup(AnswerTexts::NAME['en']) laufen. Alle anderen Sprachfassungen in AnswerTexts
 * bleiben Konstanten (Entscheid im Auftrag) und werden nicht extrahiert.
 */
function i18n_all_const_sources(string $dir): array {
  $res = [];
  foreach (i18n_files($dir) as $f) {
    if (!preg_match('/\.php$/', $f)) {
      continue;
    }
    $src = (string) file_get_contents($f);
    $rel = substr($f, strlen(i18n_root()) + 1);
    if (preg_match('/TranslatableMarkup\(\s*self::SOURCE\[/', $src)
      && preg_match('/const SOURCE = \[(.*?)\n  \];/s', $src, $m, PREG_OFFSET_CAPTURE)) {
      $base = substr_count(substr($src, 0, $m[1][1]), "\n") + 1;
      foreach (explode("\n", $m[1][0]) as $i => $line) {
        if (preg_match('/^\s*\'\w+\'\s*=>\s*(\'(?:[^\'\\\\]|\\\\.)*\')\s*,\s*$/', $line, $v)) {
          $res[] = ['id' => i18n_unq($v[1]), 'where' => "$rel:" . ($base + $i)];
        }
      }
    }
    if (preg_match_all('/TranslatableMarkup\(\s*AnswerTexts::(\w+)\[\'en\'\]/', $src, $mm)) {
      $at = (string) @file_get_contents(dirname($f) . '/../AnswerTexts.php') ?: (string) @file_get_contents(dirname($f, 2) . '/src/AnswerTexts.php');
      foreach (array_unique($mm[1]) as $name) {
        if (preg_match('/const ' . $name . ' = \[(.*?)\n  \];/s', $at, $c) && preg_match('/\'en\'\s*=>\s*(\'(?:[^\'\\\\]|\\\\.)*\')/s', $c[1], $v)) {
          $res[] = ['id' => i18n_unq($v[1]), 'where' => "AnswerTexts::$name"];
        }
      }
    }
  }
  return $res;
}
