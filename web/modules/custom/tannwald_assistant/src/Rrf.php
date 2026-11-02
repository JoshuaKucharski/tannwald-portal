<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

/**
 * Reciprocal Rank Fusion über mehrere Trefferlisten.
 *
 * Score(chunk) = Σ über alle Listen gewicht / (k + rang), rang ab 1, Gewicht 1
 * wenn nicht angegeben (6.1: Sicherheitsliste mit 2). Identität eines
 * Treffers ist die Chunk-ID (Schlüssel "id"). matched_by ist der Name der
 * Liste, oder "both", wenn der Treffer in mehr als einer Liste steht.
 * Gleichstand wird stabil nach nid, chunk_index, id aufgelöst.
 */
final class Rrf {

  public const DEFAULT_K = 60;

  /**
   * Fusioniert Trefferlisten.
   *
   * @param array<string, array<int, array>> $lists
   *   Listenname (keyword, vector) => Treffer-Zeilen des ChunkStore in
   *   Rangfolge (bester zuerst); jede Zeile braucht id, nid, chunk_index.
   * @param int $k
   *   RRF-Konstante (Standard 60).
   * @param array<string, float> $weights
   *   Gewicht je Listenname (Standard 1.0).
   *
   * @return array<int, array>
   *   Zeilen der ersten Liste, in der der Treffer vorkam, mit score
   *   (RRF-Summe), matched_by und ranks {liste: rang}; absteigend nach score.
   */
  public static function fuse(array $lists, int $k = self::DEFAULT_K, array $weights = []): array {
    if ($k < 1) {
      throw new \InvalidArgumentException('RRF k must be at least 1.');
    }
    $fused = [];
    foreach ($lists as $listName => $rows) {
      $rank = 0;
      foreach (array_values($rows) as $row) {
        $id = (int) $row['id'];
        // Doppelte Zeilen innerhalb einer Liste zählen nur beim ersten Rang.
        if (isset($fused[$id]['ranks'][$listName])) {
          continue;
        }
        $rank++;
        if (!isset($fused[$id])) {
          $fused[$id] = $row;
          $fused[$id]['score'] = 0.0;
          $fused[$id]['ranks'] = [];
        }
        $fused[$id]['ranks'][$listName] = $rank;
        $fused[$id]['score'] += ($weights[$listName] ?? 1.0) / ($k + $rank);
      }
    }
    foreach ($fused as &$row) {
      $row['matched_by'] = count($row['ranks']) > 1 ? 'both' : (string) array_key_first($row['ranks']);
    }
    unset($row);

    $result = array_values($fused);
    usort($result, static function (array $a, array $b): int {
      if (abs($a['score'] - $b['score']) > 1e-12) {
        return $b['score'] <=> $a['score'];
      }
      return [(int) $a['nid'], (int) $a['chunk_index'], (int) $a['id']]
        <=> [(int) $b['nid'], (int) $b['chunk_index'], (int) $b['id']];
    });
    return $result;
  }

}
