<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Result;

/**
 * Ergebnis des Retrievers (unveränderlich).
 *
 * Vertrag 4.1 (a): hits[] mit {n, chunk_id, nid, vid, langcode, chunk_index,
 * heading_path, title, version, text, dokumenttyp_tid, maschinentyp_tids,
 * exportklasse, score, matched_by, url}; n beginnt bei 1 und ist die Nummer,
 * unter der der Treffer im Prompt steht und als [n] zitiert wird.
 */
final class RetrievalResult {

  public const MODE_HYBRID = 'hybrid';
  public const MODE_FULLTEXT = 'volltext';

  /**
   * Schlüssel eines Treffers in der Reihenfolge von toArray().
   */
  public const HIT_KEYS = [
    'n',
    'chunk_id',
    'nid',
    'vid',
    'langcode',
    'chunk_index',
    'heading_path',
    'title',
    'version',
    'text',
    'dokumenttyp_tid',
    'maschinentyp_tids',
    'exportklasse',
    'score',
    'matched_by',
    'url',
  ];

  /**
   * Treffer, neu nummeriert ab 1.
   *
   * @var array<int, array<string, mixed>>
   */
  public readonly array $hits;

  /**
   * Erzeugt das Ergebnis.
   *
   * @param string $frage
   *   Die Frage, wie sie gesucht wurde.
   * @param string $langcode
   *   Erkannte Sprache der Frage (de, en, fr).
   * @param string $modus
   *   Modus: hybrid oder volltext.
   * @param array $hits
   *   Treffer (siehe HIT_KEYS); fehlende Schlüssel werden ergänzt, n wird in
   *   der gegebenen Reihenfolge ab 1 vergeben.
   * @param array $facets
   *   Facetten {dokumenttyp: {tid: count}, maschinentyp: {tid: count},
   *   sprache: {langcode: count}} über die gefilterte Trefferliste vor Top-k.
   * @param int $dauerMs
   *   Dauer des Retrievals in Millisekunden.
   */
  public function __construct(
    public readonly string $frage,
    public readonly string $langcode,
    public readonly string $modus,
    array $hits = [],
    public readonly array $facets = [],
    public readonly int $dauerMs = 0,
  ) {
    if (!in_array($modus, [self::MODE_HYBRID, self::MODE_FULLTEXT], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown retrieval mode "%s".', $modus));
    }
    $normalized = [];
    $n = 1;
    foreach (array_values($hits) as $hit) {
      $row = [];
      foreach (self::HIT_KEYS as $key) {
        $row[$key] = $hit[$key] ?? self::defaultFor($key);
      }
      $row['n'] = $n++;
      $row['maschinentyp_tids'] = array_values(array_map('intval', (array) $row['maschinentyp_tids']));
      $normalized[] = $row;
    }
    $this->hits = $normalized;
  }

  /**
   * Leeres Ergebnis (keine Keys, keine Treffer).
   */
  public static function empty(string $frage, string $langcode, string $modus = self::MODE_HYBRID, int $dauerMs = 0): self {
    return new self($frage, $langcode, $modus, [], [
      'dokumenttyp' => [],
      'maschinentyp' => [],
      'sprache' => [],
    ], $dauerMs);
  }

  /**
   * TRUE, wenn kein Treffer vorliegt.
   */
  public function isEmpty(): bool {
    return $this->hits === [];
  }

  /**
   * Anzahl der Treffer (höchstes gültiges n).
   */
  public function count(): int {
    return count($this->hits);
  }

  /**
   * Treffer mit der Nummer n oder NULL.
   */
  public function hit(int $n): ?array {
    return $this->hits[$n - 1] ?? NULL;
  }

  /**
   * Vollständige Darstellung (4.2 Suche, 4.3 Evals, Hand-Läufe).
   */
  public function toArray(): array {
    return [
      'frage' => $this->frage,
      'langcode' => $this->langcode,
      'modus' => $this->modus,
      'dauer_ms' => $this->dauerMs,
      'facets' => $this->facets,
      'hits' => $this->hits,
    ];
  }

  /**
   * Standardwert je Treffer-Schlüssel.
   */
  private static function defaultFor(string $key): mixed {
    return match ($key) {
      'n', 'chunk_id', 'nid', 'vid', 'chunk_index', 'dokumenttyp_tid' => 0,
      'score' => 0.0,
      'maschinentyp_tids' => [],
      'matched_by' => 'keyword',
      default => '',
    };
  }

}
