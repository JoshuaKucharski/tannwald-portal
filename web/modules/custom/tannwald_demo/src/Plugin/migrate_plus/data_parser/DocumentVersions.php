<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Plugin\migrate_plus\data_parser;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\migrate\MigrateException;
use Drupal\migrate_plus\Attribute\DataParser;
use Drupal\migrate_plus\Plugin\migrate_plus\data_parser\Json;

/**
 * Dokument-Manifest als eine Zeile je (id, version).
 *
 * Fixtures/dokumente/manifest.json verschachtelt die Versionen; eine Version
 * mit mehreren Sprachen wird zu einer Zeile mit der Liste "files" (je
 * Sprache langcode, datei, aenderungsvermerk). Reihenfolge = Manifest-
 * Reihenfolge, damit die Revisionen in dieser Folge entstehen.
 */
#[DataParser(
  id: 'tannwald_document_versions',
  title: new TranslatableMarkup('Tannwald document manifest, one row per version'),
)]
final class DocumentVersions extends Json {

  /**
   * {@inheritdoc}
   */
  protected function getSourceData(string $url, string|int $item_selector = '') {
    $documents = parent::getSourceData($url, '');
    if (!is_array($documents)) {
      throw new MigrateException(sprintf('Manifest %s is not a JSON list.', $url));
    }
    $rows = self::flatten($documents);
    // Prüfsumme der HTML-Dateien: track_changes erkennt so auch geänderte
    // Dateien, nicht nur geänderte Manifest-Zeilen.
    $directory = dirname($url);
    foreach ($rows as &$row) {
      $hashes = [];
      foreach ($row['files'] as $file) {
        $path = $directory . '/' . basename((string) $file['datei']);
        $hashes[] = is_file($path) ? (string) md5_file($path) : 'missing';
      }
      $row['checksum'] = md5(implode(' ', $hashes));
    }
    unset($row);
    return $rows;
  }

  /**
   * Flacht das Manifest ab.
   *
   * @param array<int, array<string, mixed>> $documents
   *   Manifest-Einträge.
   *
   * @return array<int, array<string, mixed>>
   *   Eine Zeile je (id, version).
   */
  public static function flatten(array $documents): array {
    $rows = [];
    foreach ($documents as $document) {
      foreach ($document['versionen'] ?? [] as $entry) {
        $key = $document['id'] . '|' . $entry['version'];
        if (!isset($rows[$key])) {
          $rows[$key] = [
            'id' => $document['id'],
            'version' => (string) $entry['version'],
            'titel' => $document['titel'],
            'dokumenttyp' => $document['dokumenttyp'],
            'maschinentypen' => $document['maschinentypen'] ?? [],
            'seriennummer' => $document['seriennummer'] ?? '',
            'exportklasse' => $document['exportklasse'] ?? 'keine',
            'freigegeben_am' => $entry['freigegeben_am'] ?? '',
            'gueltig_bis' => $entry['gueltig_bis'] ?? '',
            'files' => [],
          ];
        }
        $rows[$key]['files'][] = [
          'langcode' => $entry['langcode'],
          'datei' => $entry['datei'],
          'aenderungsvermerk' => $entry['aenderungsvermerk'] ?? '',
        ];
      }
    }
    return array_values($rows);
  }

}
