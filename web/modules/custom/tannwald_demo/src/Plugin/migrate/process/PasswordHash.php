<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Plugin\migrate\process;

use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hasht ein Klartext-Passwort für die Destination entity:user.
 *
 * Entity:user setzt pass->pre_hashed = TRUE (EntityUser::save()); ein
 * Klartext "demo" landete sonst ungehasht in der Datenbank und der Login
 * schlüge fehl.
 */
#[MigrateProcess('tannwald_password_hash')]
final class PasswordHash extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected PasswordInterface $password) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('password'));
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return $this->password->hash((string) $value);
  }

}
