<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
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

  /**
   * Bildzweck (manifest.json "zweck") => [Entity-Typ, Vokabular oder NULL].
   *
   * "hero" fehlt bewusst: das Login-Bild ist ein Theme-Asset ohne Entity.
   */
  public const IMAGE_PURPOSES = [
    'typ' => ['taxonomy_term', 'maschinentyp'],
    'dokumenttyp' => ['taxonomy_term', 'dokumenttyp'],
    'maschine' => ['node', NULL],
    'dokument' => ['node', NULL],
  ];

  /**
   * Bildfeld an allen Zielen (Aufgabe 2.2).
   */
  public const IMAGE_FIELD = 'field_bild';

  /**
   * Bild-Manifest relativ zu fixtures/.
   */
  public const IMAGE_MANIFEST = 'bilder/manifest.json';

  /**
   * Gelesenes Bild-Manifest (je Request einmal).
   *
   * @var array<int, array<string, mixed>>|null
   */
  protected ?array $imageManifest = NULL;

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

  /**
   * Ziel-Entity eines Bildes (manifest.json: zweck, ziel).
   *
   * Typ → Term maschinentyp per Code, dokumenttyp → Term per Slug,
   * maschine → Node per Seriennummer, dokument → Node (Dokument oder
   * Bulletin) per Manifest-ID. NULL für hero und unbekannte Ziele.
   */
  public function imageTarget(string $purpose, string $target): ?FieldableEntityInterface {
    if (!isset(self::IMAGE_PURPOSES[$purpose])) {
      return NULL;
    }
    [$entityTypeId, $vocab] = self::IMAGE_PURPOSES[$purpose];
    $id = $vocab === NULL ? $this->nidOf($target) : $this->termId($vocab, $target);
    if ($id === NULL) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($id);
    return $entity instanceof FieldableEntityInterface && $entity->hasField(self::IMAGE_FIELD) ? $entity : NULL;
  }

  /**
   * File-ID des Bildes am Ziel ('typ', 'TB200') oder NULL.
   */
  public function imageFor(string $purpose, string $target): ?int {
    $entity = $this->imageTarget($purpose, $target);
    $fid = $entity?->get(self::IMAGE_FIELD)->target_id;
    return empty($fid) ? NULL : (int) $fid;
  }

  /**
   * Alt-Text einer Bilddatei in einer Sprache aus dem Manifest.
   *
   * Das Feld field_bild ist nicht übersetzbar und trägt den deutschen
   * Alt-Text; en/fr liefert das Manifest (Theme-Helfer tannwald_image()).
   */
  public function imageAlt(string $filename, string $langcode): ?string {
    foreach ($this->imageManifest() as $entry) {
      if (($entry['datei'] ?? NULL) === basename($filename)) {
        $alt = $entry['alt'][$langcode] ?? NULL;
        return is_string($alt) && $alt !== '' ? $alt : NULL;
      }
    }
    return NULL;
  }

  /**
   * Einträge aus fixtures/bilder/manifest.json (leer, wenn die Datei fehlt).
   *
   * @return array<int, array<string, mixed>>
   *   Manifest-Zeilen {datei, zweck, ziel, alt: {de, en, fr}}.
   */
  public function imageManifest(): array {
    if ($this->imageManifest === NULL) {
      $path = FixturePath::resolve(self::IMAGE_MANIFEST);
      $data = is_readable($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
      $this->imageManifest = is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
    return $this->imageManifest;
  }

}
