<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\tannwald_demo\FixturePath;

/**
 * Liest den Inhalt einer Fixture-Datei (HTML-Körper eines Bulletins).
 *
 * Konfiguration "directory": Unterordner von fixtures/ (z. B. bulletins).
 */
#[MigrateProcess('tannwald_fixture_file')]
final class FixtureFile extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $path = FixturePath::resolve(trim((string) ($this->configuration['directory'] ?? ''), '/') . '/' . basename((string) $value));
    if (!is_file($path) || !is_readable($path)) {
      throw new MigrateException(sprintf('Fixture file %s is missing.', $path));
    }
    return trim((string) file_get_contents($path));
  }

}
