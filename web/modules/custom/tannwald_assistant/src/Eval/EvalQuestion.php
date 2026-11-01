<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

use Symfony\Component\Yaml\Yaml;

/**
 * Eine Frage des Eval-Sets (RunBook §1.1, Pflichtenheft §8).
 */
final class EvalQuestion {

  public const EXPECTED = ['antwort', 'ablehnung', 'safety'];

  /**
   * Erzeugt die Frage.
   *
   * @param string $id
   *   Frage-ID (q-001 …).
   * @param string $frage
   *   Fragetext.
   * @param string $als
   *   Demo-Konto (Name).
   * @param string $sprache
   *   Sprache der Frage.
   * @param array<int, array{dokument: string, version: ?string, kapitel: string}> $erwarteteQuellen
   *   Erwartete Quellen.
   * @param string $erwartet
   *   Erwartung: antwort, ablehnung oder safety.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $frage,
    public readonly string $als,
    public readonly string $sprache,
    public readonly array $erwarteteQuellen,
    public readonly string $erwartet,
  ) {}

  /**
   * Liest und prüft ein Set; Fehler sammelt EvalSetException je ID.
   *
   * @return \Drupal\tannwald_assistant\Eval\EvalQuestion[]
   *   Fragen in Set-Reihenfolge.
   */
  public static function loadSet(string $path): array {
    if (!is_file($path)) {
      throw new EvalSetException(['set' => 'file not found: ' . $path]);
    }
    $data = Yaml::parseFile($path);
    if (!is_array($data) || !array_is_list($data)) {
      throw new EvalSetException(['set' => 'expected a YAML list of questions']);
    }
    $questions = [];
    $errors = [];
    foreach ($data as $index => $row) {
      $id = is_array($row) && is_string($row['id'] ?? NULL) ? $row['id'] : '#' . ($index + 1);
      try {
        $questions[] = self::fromArray(is_array($row) ? $row : [], $id);
      }
      catch (\InvalidArgumentException $e) {
        $errors[$id] = $e->getMessage();
      }
    }
    $ids = array_map(static fn (EvalQuestion $q): string => $q->id, $questions);
    foreach (array_unique(array_diff_assoc($ids, array_unique($ids))) as $duplicate) {
      $errors[$duplicate] = 'duplicate id';
    }
    if ($errors !== []) {
      throw new EvalSetException($errors);
    }
    return $questions;
  }

  /**
   * Baut eine Frage aus einer YAML-Zeile.
   */
  private static function fromArray(array $row, string $id): self {
    foreach (['id', 'frage', 'als', 'sprache', 'erwartet'] as $key) {
      if (!is_string($row[$key] ?? NULL) || trim($row[$key]) === '') {
        throw new \InvalidArgumentException('missing ' . $key);
      }
    }
    if (!in_array($row['erwartet'], self::EXPECTED, TRUE)) {
      throw new \InvalidArgumentException('erwartet must be antwort, ablehnung or safety');
    }
    $sources = [];
    foreach ((array) ($row['erwartete_quellen'] ?? []) as $source) {
      if (!is_array($source) || !is_string($source['dokument'] ?? NULL) || !is_string($source['kapitel'] ?? NULL)) {
        throw new \InvalidArgumentException('erwartete_quellen needs dokument and kapitel');
      }
      $version = $source['version'] ?? NULL;
      $sources[] = [
        'dokument' => $source['dokument'],
        'version' => $version === NULL || $version === '' ? NULL : (string) $version,
        'kapitel' => $source['kapitel'],
      ];
    }
    if ($sources === [] && $row['erwartet'] !== 'ablehnung') {
      throw new \InvalidArgumentException('only ablehnung may have no erwartete_quellen');
    }
    return new self($id, trim($row['frage']), trim($row['als']), trim($row['sprache']), $sources, $row['erwartet']);
  }

}
