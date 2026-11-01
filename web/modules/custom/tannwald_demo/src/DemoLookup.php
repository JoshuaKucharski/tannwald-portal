<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;

/**
 * Nachschlagen von Demo-Daten: Manifest-ID → nid, Code/Slug → Term-ID.
 *
 * Vertrag an 4.3 (Evals: dokument-ID → nid) und 6.1. Quelle für nidOf() sind
 * die Map-Tabellen der Migrationen, nicht Titel (Titel sind übersetzbar).
 */
class DemoLookup {

  /**
   * Migrationen, deren erste Quell-ID eine Manifest- bzw. Seriennummer ist.
   */
  public const NODE_MIGRATIONS = ['tannwald_dokumente', 'tannwald_bulletins', 'tannwald_maschinen'];

  /**
   * Feld je Vokabular, über das ein Code nachgeschlagen wird.
   */
  public const CODE_FIELDS = [
    'maschinentyp' => 'field_typ_code',
    'dokumenttyp' => 'field_slug',
    'firma' => 'field_sap_nr',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MigrationPluginManagerInterface $migrationManager,
  ) {}

  /**
   * Node-ID zu einer Manifest-ID (ba-tb200, SB-2026-07, TB200-2021-0417).
   */
  public function nidOf(string $manifestId): ?int {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach (self::NODE_MIGRATIONS as $migrationId) {
      /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
      $migration = $this->migrationManager->createInstance($migrationId);
      foreach ($migration->getIdMap()->lookupDestinationIds([$manifestId]) as $ids) {
        $nid = (int) ($ids[0] ?? 0);
        if ($nid > 0 && $storage->load($nid) !== NULL) {
          return $nid;
        }
      }
    }
    return NULL;
  }

  /**
   * Term-ID zu Code (maschinentyp), Slug (dokumenttyp) oder SAP-Nr. (firma).
   *
   * "TB-200" und "TB200" sind gleichwertig; ohne Code-Treffer zählt der
   * Term-Name. Bei Duplikaten gewinnt die kleinste Term-ID (wie
   * tannwald_access).
   */
  public function termId(string $vocab, string $code): ?int {
    $code = trim($code);
    if ($code === '' || !isset(self::CODE_FIELDS[$vocab])) {
      return NULL;
    }
    $value = $vocab === 'maschinentyp' ? strtoupper(str_replace(['-', ' '], '', $code)) : $code;
    $query = fn(string $field, string $needle): array => $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocab)
      ->condition($field, $needle)
      ->sort('tid')
      ->range(0, 1)
      ->execute();
    $tids = $query(self::CODE_FIELDS[$vocab], $value) ?: $query('name', $code);
    return $tids === [] ? NULL : (int) reset($tids);
  }

  /**
   * Nutzer-ID eines Kontos (Name) oder NULL.
   */
  public function uidOf(string $accountName): ?int {
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $accountName)
      ->range(0, 1)
      ->execute();
    return $uids === [] ? NULL : (int) reset($uids);
  }

}
