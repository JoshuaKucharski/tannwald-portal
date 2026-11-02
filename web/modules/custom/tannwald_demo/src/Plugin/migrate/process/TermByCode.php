<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\tannwald_demo\DemoLookup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Term-ID über Code (maschinentyp: field_typ_code) oder Slug (dokumenttyp).
 *
 * "TB-200" und "TB200" treffen denselben Term; ein unbekannter Wert lässt die
 * Zeile scheitern (sichtbar in der Zählung), statt still leer zu bleiben.
 * Konfiguration "vocabulary": maschinentyp oder dokumenttyp.
 */
#[MigrateProcess('tannwald_term')]
final class TermByCode extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected DemoLookup $lookup) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get(DemoLookup::class));
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $vocabulary = (string) ($this->configuration['vocabulary'] ?? '');
    $tid = $this->lookup->termId($vocabulary, (string) $value);
    if ($tid === NULL) {
      throw new MigrateException(sprintf('No %s term for "%s" (demo-install creates the terms first).', $vocabulary, $value));
    }
    return $tid;
  }

}
