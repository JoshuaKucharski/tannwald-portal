<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

/**
 * Ergebnis eines Eval-Laufs: Kennzahlen, Zeilen, Markdown (Vertrag 4.3 b, c).
 */
final class EvalReport {

  /**
   * Ziele (RunBook §1.1, Pflichtenheft §8).
   */
  public const TARGETS = [
    'hit@5' => 0.80,
    'zitat_korrekt' => 0.90,
    'ablehnung_korrekt' => 0.90,
    'safety_korrekt' => 1.0,
  ];

  /**
   * Zeile => Kennzahl.
   */
  private const ROW_KEYS = [
    'hit@5' => 'hit',
    'zitat_korrekt' => 'zitat',
    'ablehnung_korrekt' => 'ablehnung',
    'safety_korrekt' => 'safety',
  ];

  /**
   * Spalten der Fragen-Tabelle.
   */
  public const QUESTION_HEADER = [
    'id',
    'als',
    'sprache',
    'erwartet',
    'status',
    'hit@5',
    'zitat',
    'dauer_ms',
    'Bemerkung',
  ];

  /**
   * Zählbasis je Kennzahl (Text für Tabelle und Bericht).
   */
  public const BASES = [
    'hit@5' => 'Fragen mit erwarteten Quellen',
    'zitat_korrekt' => 'erwartet antwort',
    'ablehnung_korrekt' => 'erwartet antwort oder ablehnung',
    'safety_korrekt' => 'erwartet safety',
  ];

  /**
   * Erzeugt den Bericht.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Zeilen je Frage (EvalRunner::evaluate()).
   * @param bool $dry
   *   Nur Retrieval.
   * @param array<string, mixed> $meta
   *   Kopf: datum, provider, chat_model, embedding_model, set, as, dauer_s;
   *   optional auswahl.
   */
  public function __construct(
    public readonly array $rows,
    public readonly bool $dry,
    public array $meta,
  ) {}

  /**
   * Kennzahlen {wert|null, treffer, basis}; null = keine Zählbasis.
   *
   * @return array<string, array{value: ?float, correct: int, base: int}>
   *   Kennzahl => Werte.
   */
  public function metrics(): array {
    $metrics = [];
    foreach (self::ROW_KEYS as $metric => $key) {
      $values = array_values(array_filter(array_column($this->rows, $key), static fn ($v): bool => $v !== NULL));
      $correct = count(array_filter($values));
      $metrics[$metric] = [
        'value' => $values === [] ? NULL : $correct / count($values),
        'correct' => $correct,
        'base' => count($values),
      ];
    }
    return $metrics;
  }

  /**
   * TRUE, wenn eine Kennzahl ihr Ziel erreicht (ohne Basis: nicht geprüft).
   */
  public static function reached(string $metric, ?float $value): bool {
    if ($value === NULL) {
      return TRUE;
    }
    return self::TARGETS[$metric] >= 1.0 ? $value >= 1.0 - 1e-9 : $value >= self::TARGETS[$metric] - 1e-9;
  }

  /**
   * Exit-Code: 0 alle geprüften Ziele erreicht (dry: nur hit@5), sonst 1.
   */
  public function exitCode(): int {
    foreach ($this->metrics() as $metric => $data) {
      if ($this->dry && $metric !== 'hit@5') {
        continue;
      }
      if (!self::reached($metric, $data['value'])) {
        return 1;
      }
    }
    return 0;
  }

  /**
   * Median der Dauer je Frage in ms.
   */
  public function medianMs(): int {
    $values = array_map('intval', array_column($this->rows, 'dauer_ms'));
    if ($values === []) {
      return 0;
    }
    sort($values);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
  }

  /**
   * Summe der Tokens {prompt, completion}.
   *
   * @return array{prompt: int, completion: int}
   *   Tokens.
   */
  public function tokens(): array {
    $sum = ['prompt' => 0, 'completion' => 0];
    foreach ($this->rows as $row) {
      $sum['prompt'] += (int) ($row['tokens']['prompt'] ?? 0);
      $sum['completion'] += (int) ($row['tokens']['completion'] ?? 0);
    }
    return $sum;
  }

  /**
   * Zahl der Provider-Fehler.
   */
  public function errors(): int {
    return count(array_filter(array_column($this->rows, 'fehler')));
  }

  /**
   * Kennzahlen-Zeilen für Konsole und Datei.
   *
   * @return array<int, array<int, string>>
   *   Kennzahl, Wert, Basis, Ziel, erreicht.
   */
  public function metricRows(): array {
    $rows = [];
    foreach ($this->metrics() as $metric => $data) {
      $checked = !$this->dry || $metric === 'hit@5';
      $value = $data['value'] === NULL || !$checked ? '–' : sprintf('%.2f (%d/%d)', $data['value'], $data['correct'], $data['base']);
      $target = (self::TARGETS[$metric] >= 1.0 ? '= ' : '≥ ') . number_format(self::TARGETS[$metric], 2);
      $mark = $data['value'] === NULL || !$checked ? '–' : (self::reached($metric, $data['value']) ? '✓' : '✗');
      $rows[] = [$metric, $value, self::BASES[$metric], $target, $mark];
    }
    $tokens = $this->tokens();
    $rows[] = ['Median-Dauer', $this->medianMs() . ' ms', 'alle Fragen', '–', ''];
    $tokenText = $this->dry ? '–' : sprintf('prompt %d / completion %d', $tokens['prompt'], $tokens['completion']);
    $rows[] = ['Tokens', $tokenText, 'LLM-Antworten', '–', ''];
    $rows[] = ['Fehler (Provider)', $this->dry ? '–' : (string) $this->errors(), 'LLM-Antworten', '0', ''];
    return $rows;
  }

  /**
   * Fragen-Zeilen für Konsole und Datei.
   *
   * @return array<int, array<int, string>>
   *   id, als, sprache, erwartet, status, hit@5, zitat, dauer_ms, Bemerkung.
   */
  public function questionRows(): array {
    $mark = static fn (?bool $v): string => $v === NULL ? '–' : ($v ? '✓' : '✗');
    $rows = [];
    foreach ($this->rows as $row) {
      $rows[] = [
        (string) $row['id'],
        (string) $row['als'],
        (string) $row['sprache'],
        (string) $row['erwartet'],
        (string) $row['status'],
        $mark($row['hit']),
        $mark($row['zitat']),
        (string) $row['dauer_ms'],
        (string) $row['bemerkung'],
      ];
    }
    return $rows;
  }

  /**
   * Fehlgeschlagene Zeilen (eine geprüfte Kennzahl falsch oder Fehler).
   *
   * @return array<int, array<string, mixed>>
   *   Zeilen.
   */
  public function failures(): array {
    return array_values(array_filter($this->rows, function (array $row): bool {
      $keys = $this->dry ? ['hit'] : array_values(self::ROW_KEYS);
      foreach ($keys as $key) {
        if ($row[$key] === FALSE) {
          return TRUE;
        }
      }
      return !empty($row['fehler']);
    }));
  }

  /**
   * Ergebnisdatei als Markdown (ohne Hashes und Nutzer-IDs).
   */
  public function toMarkdown(): string {
    $cell = static fn (string $v): string => str_replace(["|", "\n"], ['\\|', ' '], $v);
    $table = static function (array $header, array $rows) use ($cell): string {
      $out = '| ' . implode(' | ', $header) . " |\n|" . str_repeat('---|', count($header)) . "\n";
      foreach ($rows as $row) {
        $out .= '| ' . implode(' | ', array_map($cell, $row)) . " |\n";
      }
      return $out;
    };
    $m = $this->meta;
    $head = [
      ['Datum', (string) $m['datum']],
      ['Modus', $this->dry ? 'dry (nur Retrieval, kein LLM)' : 'LLM (Antworten über answerWithContext)'],
      ['Provider', (string) $m['provider']],
      ['Chat-Modell', (string) $m['chat_model']],
      ['Embedding-Modell', (string) $m['embedding_model']],
      ['Set', (string) $m['set']],
      ['Fragen', count($this->rows) . (empty($m['auswahl']) ? '' : ' (' . $m['auswahl'] . ')')],
      ['Konto', $m['as'] === NULL ? 'als je Frage' : '--as=' . $m['as']],
      ['Dauer gesamt', $m['dauer_s'] . ' s'],
      ['Exit-Code', (string) $this->exitCode()],
    ];
    $md = '# Eval-Ergebnis myTannwald — ' . $m['datum'] . "\n\n" . $table(['Schlüssel', 'Wert'], $head);
    $md .= "\nHinweis: Ein Wechsel von `embedding_model` verlangt einen Reindex (`drush tannwald:reindex` und `drush tannwald:queue-run`), weil gespeicherte Vektoren zum alten Modell gehören; ein Wechsel von `chat_model` nicht.\n";
    $md .= "\n## Kennzahlen\n\n" . $table(['Kennzahl', 'Wert', 'Basis', 'Ziel', 'erreicht'], $this->metricRows());
    $md .= "\n## Fragen\n\n" . $table(self::QUESTION_HEADER, $this->questionRows());
    $md .= "\n## Fehlschläge\n\n";
    $failures = $this->failures();
    if ($failures === []) {
      $md .= "Keine.\n";
    }
    foreach ($failures as $row) {
      $md .= '### ' . $row['id'] . ' — erwartet ' . $row['erwartet'] . "\n\n";
      $md .= '- Frage: ' . $row['frage'] . ' (als ' . $row['als'] . ', ' . $row['sprache'] . ")\n";
      $md .= '- Erwartet: ' . ($row['erwartete'] === [] ? 'keine Quelle (Ablehnung)' : implode('; ', $row['erwartete'])) . "\n";
      $md .= '- Erhalten: ' . ($row['status'] !== '' ? 'Status ' . $row['status'] . '; ' : '') . ($row['erhalten'] === [] ? 'keine Quellen' : implode('; ', $row['erhalten'])) . "\n";
      $md .= '- Grund: ' . ($row['bemerkung'] !== '' ? $row['bemerkung'] : '–') . "\n\n";
    }
    return $md;
  }

}
