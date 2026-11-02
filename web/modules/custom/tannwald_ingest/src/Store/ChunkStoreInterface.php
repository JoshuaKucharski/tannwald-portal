<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Store;

/**
 * Speicher für Chunks samt Embeddings und Access-Keys (Tabelle tannwald_chunk).
 *
 * Vertrag aus RunBook §1.1 (wörtlich). Treffer-Zeilen von keyword() und
 * similar() haben die Form:
 * @code
 * [
 *   'id' => 17, 'nid' => 42, 'vid' => 108, 'langcode' => 'de',
 *   'chunk_index' => 3,
 *   'heading_path' => '9 Störungen › 9.4 Störungen an der Siegelstation',
 *   'title' => 'Betriebsanleitung TB-200', 'text' => '…',
 *   'exportklasse' => 'keine', 'dokumenttyp_tid' => 5,
 *   'maschinentyp_tids' => [1], 'score' => 4.21,
 * ]
 * @endcode
 *
 * Score-Skalen: keyword() = gewichtete MATCH-Summe (title*3 + heading_path*2
 * + text*1, unbegrenzt); similar() = Cosinus (0–1 bei normalisierten
 * Embeddings). Konsumenten normalisieren über RRF, nicht über die Skalen.
 *
 * Leere $accessKeys liefern immer ein leeres Ergebnis (nie „alles“).
 */
interface ChunkStoreInterface {

  /**
   * Ersetzt alle Chunks einer Revision und Sprache (idempotent).
   *
   * @param int $nid
   *   Node-ID.
   * @param int $vid
   *   Revisions-ID.
   * @param string $langcode
   *   Sprache der Übersetzung.
   * @param array $chunks
   *   Liste von Chunks: {chunk_index, heading_path, title, text, token_count,
   *   embedding: float[], access_keys: string[], exportklasse,
   *   dokumenttyp_tid, maschinentyp_tids: int[]}. Vorher werden alle Zeilen
   *   dieser nid+vid+langcode gelöscht.
   */
  public function replaceForRevision(int $nid, int $vid, string $langcode, array $chunks): void;

  /**
   * Löscht alle Chunks eines Nodes (alle Revisionen und Sprachen).
   */
  public function deleteForNode(int $nid): void;

  /**
   * Löscht alle Chunks einer Revision (alle Sprachen).
   */
  public function deleteForRevision(int $nid, int $vid): void;

  /**
   * Volltextsuche (MariaDB FULLTEXT, BOOLEAN MODE ohne Operatoren).
   *
   * @param string $query
   *   Freitext; Operatorzeichen werden entfernt.
   * @param string[] $accessKeys
   *   Keys des Nutzers (EntitlementService::getRealmsForAccount()).
   * @param int $limit
   *   Höchstzahl Treffer.
   *
   * @return array
   *   Treffer-Zeilen, sortiert nach score DESC, nid, chunk_index.
   */
  public function keyword(string $query, array $accessKeys, int $limit): array;

  /**
   * Vektorsuche: Cosinus in PHP, access_keys-Vorfilter in SQL.
   *
   * @param float[] $vector
   *   Anfragevektor (1024 Dimensionen).
   * @param string[] $accessKeys
   *   Keys des Nutzers.
   * @param int $limit
   *   Höchstzahl Treffer.
   *
   * @return array
   *   Treffer-Zeilen, sortiert nach score (Cosinus) DESC.
   */
  public function similar(array $vector, array $accessKeys, int $limit): array;

  /**
   * Facetten über die Dokumente (nid+langcode) der angegebenen Chunks.
   *
   * @param int[] $chunkIds
   *   IDs aus tannwald_chunk.
   *
   * @return array
   *   {dokumenttyp: {tid: count}, maschinentyp: {tid: count},
   *   sprache: {langcode: count}} — je Dokument einmal gezählt.
   */
  public function facetCounts(array $chunkIds): array;

}
