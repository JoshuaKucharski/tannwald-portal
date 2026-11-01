<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

use Drupal\Core\Site\Settings;

/**
 * Pfad zum Ordner fixtures/ im Repo-Stamm (neben web/).
 *
 * Die Migrationen nennen ihre Quellen relativ zu diesem Ordner; produktiv
 * (SAP/OData) ersetzt eine URL den Pfad, die Migration bleibt dieselbe.
 * Abweichender Ort über $settings['tannwald_demo_fixtures_path'].
 */
final class FixturePath {

  /**
   * Absoluter Pfad des Fixture-Ordners ohne abschliessenden Schrägstrich.
   */
  public static function directory(): string {
    $configured = Settings::get('tannwald_demo_fixtures_path');
    if (is_string($configured) && $configured !== '') {
      return rtrim($configured, '/');
    }
    return dirname(\Drupal::root()) . '/fixtures';
  }

  /**
   * Absoluter Pfad einer Datei relativ zum Fixture-Ordner.
   */
  public static function resolve(string $relative): string {
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '://')) {
      return $relative;
    }
    return self::directory() . '/' . ltrim($relative, '/');
  }

}
