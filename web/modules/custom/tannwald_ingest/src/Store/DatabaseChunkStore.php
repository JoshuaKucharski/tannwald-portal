<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Store;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Chunk-Store auf MariaDB: FULLTEXT für keyword(), Cosinus in PHP für similar().
 *
 * Beide Wege filtern in SQL über access_keys; leere Nutzer-Keys liefern nie
 * Treffer. Produktiv ersetzt ein OpenSearch-Store diese Klasse (ADR-003).
 */
class DatabaseChunkStore implements ChunkStoreInterface {

  /**
   * Ab dieser Zeilenzahl im Vektor-Vorfilter warnt similar() (ADR-003).
   */
  public const SIMILAR_WARN_ROWS = 5000;

  /**
   * Gewichte der FULLTEXT-Spalten (Pflichtenheft §8).
   */
  public const WEIGHTS = ['title' => 3, 'heading_path' => 2, 'text' => 1];

  /**
   * Spalten einer Treffer-Zeile (ohne score).
   */
  protected const HIT_COLUMNS = ['id', 'nid', 'vid', 'langcode', 'chunk_index', 'heading_path', 'title', 'text', 'exportklasse', 'dokumenttyp_tid', 'maschinentyp_tids'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function replaceForRevision(int $nid, int $vid, string $langcode, array $chunks): void {
    $now = $this->time->getRequestTime();
    $rows = [];
    foreach ($chunks as $chunk) {
      $rows[] = [
        'nid' => $nid,
        'vid' => $vid,
        'langcode' => $langcode,
        'chunk_index' => (int) $chunk['chunk_index'],
        'heading_path' => mb_substr((string) $chunk['heading_path'], 0, 512),
        'title' => mb_substr((string) ($chunk['title'] ?? ''), 0, 255),
        'text' => (string) $chunk['text'],
        'token_count' => (int) ($chunk['token_count'] ?? 0),
        'embedding' => VectorPack::pack($chunk['embedding'] ?? []),
        'access_keys' => self::joinKeys($chunk['access_keys'] ?? []),
        'exportklasse' => (string) ($chunk['exportklasse'] ?? 'keine'),
        'dokumenttyp_tid' => (int) ($chunk['dokumenttyp_tid'] ?? 0),
        'maschinentyp_tids' => implode(' ', array_map('intval', $chunk['maschinentyp_tids'] ?? [])),
        'created' => $now,
      ];
    }
    // Eigene Transaktion: FULLTEXT sieht Zeilen erst nach dem Commit.
    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete('tannwald_chunk')
        ->condition('nid', $nid)
        ->condition('vid', $vid)
        ->condition('langcode', $langcode)
        ->execute();
      if ($rows !== []) {
        $insert = $this->database->insert('tannwald_chunk')->fields(array_keys($rows[0]));
        foreach ($rows as $row) {
          $insert->values($row);
        }
        $insert->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForNode(int $nid): void {
    $this->database->delete('tannwald_chunk')->condition('nid', $nid)->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForRevision(int $nid, int $vid): void {
    $this->database->delete('tannwald_chunk')->condition('nid', $nid)->condition('vid', $vid)->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function keyword(string $query, array $accessKeys, int $limit): array {
    $query = self::cleanQuery($query);
    [$keySql, $args] = $this->accessKeyFilter($accessKeys);
    if ($query === '' || $keySql === NULL || $limit < 1) {
      return [];
    }
    $matches = [];
    $score = [];
    foreach (self::WEIGHTS as $column => $weight) {
      // Je Vorkommen ein eigener Platzhalter (keine Wiederverwendung).
      $args[":s_$column"] = $query;
      $args[":w_$column"] = $query;
      $score[] = "MATCH(c.$column) AGAINST(:s_$column IN BOOLEAN MODE) * $weight";
      $matches[] = "MATCH(c.$column) AGAINST(:w_$column IN BOOLEAN MODE) > 0";
    }
    $columns = 'c.' . implode(', c.', self::HIT_COLUMNS);
    $sql = "SELECT $columns, (" . implode(' + ', $score) . ") AS score
      FROM {tannwald_chunk} c
      WHERE (" . implode(' OR ', $matches) . ") AND ($keySql)
      ORDER BY score DESC, c.nid ASC, c.chunk_index ASC";
    $result = $this->database->queryRange($sql, 0, $limit, $args);
    $hits = [];
    foreach ($result as $row) {
      $hits[] = self::hit((array) $row, (float) $row->score);
    }
    return $hits;
  }

  /**
   * {@inheritdoc}
   */
  public function similar(array $vector, array $accessKeys, int $limit): array {
    [$keySql, $args] = $this->accessKeyFilter($accessKeys);
    if ($keySql === NULL || $limit < 1 || $vector === []) {
      return [];
    }
    $query = array_map('floatval', array_values($vector));
    $queryNorm = sqrt(array_sum(array_map(static fn(float $v): float => $v * $v, $query)));
    if ($queryNorm == 0.0) {
      return [];
    }
    // Vorfilter in SQL, ohne LIMIT; Cosinus in PHP.
    $result = $this->database->query("SELECT c.id, c.nid, c.chunk_index, c.embedding
      FROM {tannwald_chunk} c
      WHERE c.embedding IS NOT NULL AND ($keySql)", $args);
    $scores = [];
    $order = [];
    $rows = 0;
    $dimension = count($query);
    foreach ($result as $row) {
      $rows++;
      $stored = VectorPack::unpack((string) $row->embedding);
      if (count($stored) !== $dimension) {
        continue;
      }
      $dot = 0.0;
      $norm = 0.0;
      foreach ($stored as $i => $value) {
        $dot += $value * $query[$i];
        $norm += $value * $value;
      }
      if ($norm == 0.0) {
        continue;
      }
      $scores[(int) $row->id] = $dot / ($queryNorm * sqrt($norm));
      $order[(int) $row->id] = [(int) $row->nid, (int) $row->chunk_index];
    }
    if ($rows > self::SIMILAR_WARN_ROWS) {
      $this->logger->notice('similar() scanned @rows chunk rows in PHP (more than @max); consider the OpenSearch store (ADR-003).', [
        '@rows' => $rows,
        '@max' => self::SIMILAR_WARN_ROWS,
      ]);
    }
    if ($scores === []) {
      return [];
    }
    uksort($scores, static function (int $a, int $b) use ($scores, $order): int {
      return [$scores[$b], $order[$a][0], $order[$a][1]] <=> [$scores[$a], $order[$b][0], $order[$b][1]];
    });
    $top = array_slice($scores, 0, $limit, TRUE);
    $rowsById = $this->database->select('tannwald_chunk', 'c')
      ->fields('c', self::HIT_COLUMNS)
      ->condition('c.id', array_keys($top), 'IN')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    $hits = [];
    foreach ($top as $id => $score) {
      if (isset($rowsById[$id])) {
        $hits[] = self::hit($rowsById[$id], $score);
      }
    }
    return $hits;
  }

  /**
   * {@inheritdoc}
   */
  public function facetCounts(array $chunkIds): array {
    $facets = ['dokumenttyp' => [], 'maschinentyp' => [], 'sprache' => []];
    $chunkIds = array_values(array_unique(array_map('intval', $chunkIds)));
    if ($chunkIds === []) {
      return $facets;
    }
    // Ein Dokument = eine Revision in einer Sprache (nid, vid, langcode).
    $result = $this->database->query('SELECT c.nid, c.vid, c.langcode, MAX(c.dokumenttyp_tid) AS dokumenttyp_tid, MAX(c.maschinentyp_tids) AS maschinentyp_tids
      FROM {tannwald_chunk} c
      WHERE c.id IN (:ids[])
      GROUP BY c.nid, c.vid, c.langcode', [':ids[]' => $chunkIds]);
    foreach ($result as $row) {
      $tid = (int) $row->dokumenttyp_tid;
      if ($tid > 0) {
        $facets['dokumenttyp'][$tid] = ($facets['dokumenttyp'][$tid] ?? 0) + 1;
      }
      foreach (self::splitTids((string) $row->maschinentyp_tids) as $typeTid) {
        $facets['maschinentyp'][$typeTid] = ($facets['maschinentyp'][$typeTid] ?? 0) + 1;
      }
      $facets['sprache'][$row->langcode] = ($facets['sprache'][$row->langcode] ?? 0) + 1;
    }
    return $facets;
  }

  /**
   * Entfernt Operatorzeichen des Boolean Mode und verdichtet Whitespace.
   *
   * Ohne Operatoren gilt ODER-Semantik; „E-4711“ wird zu „E 4711“.
   */
  public static function cleanQuery(string $query): string {
    $query = (string) preg_replace('/[+\-<>()~*"@]+/u', ' ', $query);
    return trim((string) preg_replace('/\s+/u', ' ', $query));
  }

  /**
   * Baut den access_keys-Vorfilter (OR-verknüpft, Keys als Platzhalter).
   *
   * @return array
   *   [SQL oder NULL bei leerer Key-Liste, Argumente].
   */
  protected function accessKeyFilter(array $accessKeys): array {
    $keys = self::normalizeKeys($accessKeys);
    if ($keys === []) {
      return [NULL, []];
    }
    $conditions = [];
    $args = [];
    foreach ($keys as $i => $key) {
      $conditions[] = "CONCAT(' ', c.access_keys, ' ') LIKE :key_$i";
      $args[":key_$i"] = '% ' . $this->database->escapeLike($key) . ' %';
    }
    return [implode(' OR ', $conditions), $args];
  }

  /**
   * Bereinigt eine Key-Liste (Strings ohne Whitespace, eindeutig).
   *
   * @return string[]
   *   Keys in Eingabereihenfolge.
   */
  public static function normalizeKeys(array $keys): array {
    $clean = [];
    foreach ($keys as $key) {
      $key = trim((string) $key);
      if ($key === '' || preg_match('/\s/u', $key)) {
        continue;
      }
      $clean[$key] = $key;
    }
    return array_values($clean);
  }

  /**
   * Keys als leerzeichengetrennter String für die Spalte access_keys.
   */
  protected static function joinKeys(array $keys): string {
    return implode(' ', self::normalizeKeys($keys));
  }

  /**
   * Leerzeichengetrennte Term-IDs zu int[].
   *
   * @return int[]
   *   Term-IDs.
   */
  protected static function splitTids(string $tids): array {
    return array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($tids)) ?: [])));
  }

  /**
   * Formt eine Datenbankzeile zur Treffer-Zeile des Vertrags.
   */
  protected static function hit(array $row, float $score): array {
    return [
      'id' => (int) $row['id'],
      'nid' => (int) $row['nid'],
      'vid' => (int) $row['vid'],
      'langcode' => (string) $row['langcode'],
      'chunk_index' => (int) $row['chunk_index'],
      'heading_path' => (string) $row['heading_path'],
      'title' => (string) $row['title'],
      'text' => (string) $row['text'],
      'exportklasse' => (string) $row['exportklasse'],
      'dokumenttyp_tid' => (int) $row['dokumenttyp_tid'],
      'maschinentyp_tids' => self::splitTids((string) $row['maschinentyp_tids']),
      'score' => $score,
    ];
  }

}
