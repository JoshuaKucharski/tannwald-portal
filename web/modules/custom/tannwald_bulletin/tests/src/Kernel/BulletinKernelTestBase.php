<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_bulletin\Kernel;

use Drupal\Tests\tannwald_access\Kernel\AccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Gemeinsamer Aufbau: Welt aus tannwald_access plus Lesebestätigungen.
 *
 * Welt (AccessFixturesTrait): Firma A (Kunde CH, M1 TB-200), B (Kunde BR,
 * M2 KT-500), C (Kunde AR, M3 TB-200), H (Händler BR, Gebiet BR, AR).
 */
abstract class BulletinKernelTestBase extends AccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'file',
    'node',
    'taxonomy',
    'workflows',
    'content_moderation',
    'views',
    'mailer_transport',
    'symfony_mailer',
    'symfony_mailer_test',
    'tannwald_access',
    'tannwald_bulletin',
  ];

  /**
   * Testwelt.
   *
   * @var array<string, \Drupal\Core\Entity\EntityInterface>
   */
  protected array $w;

  /**
   * Konten nach Name.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  protected array $users;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('lesebestaetigung');
    $this->installConfig(['symfony_mailer', 'tannwald_bulletin']);
    $this->installSchema('system', ['sequences']);
    $this->config('system.site')->set('name', 'myTannwald')->set('mail', 'portal@tannwald.example')->save();
    $this->addField('node', 'bulletin', 'field_bestaetigung_pflicht', 'boolean');
    $this->w = $this->createAccessWorld();
    $this->users = [
      'kunde-a' => $this->createRoleUser('kunde-a', 'kunde', self::idOf($this->w['firma_a']), 'CH'),
      'kunde-b' => $this->createRoleUser('kunde-b', 'kunde', self::idOf($this->w['firma_b']), 'BR'),
      'kunde-c' => $this->createRoleUser('kunde-c', 'kunde', self::idOf($this->w['firma_c']), 'AR'),
      'haendler' => $this->createRoleUser('haendler', 'haendler', self::idOf($this->w['firma_h']), 'BR'),
      'techniker' => $this->createRoleUser('techniker', 'techniker', NULL, 'CH'),
      'redaktion' => $this->createRoleUser('redaktion', 'redaktion', NULL, 'CH'),
      'compliance' => $this->createRoleUser('compliance', 'compliance', NULL, 'CH'),
    ];
    Role::load('compliance')->grantPermission('tannwald view audit log')->save();
    $this->rebuildGrants();
  }

  /**
   * Konto nach Name.
   */
  protected function user(string $name): UserInterface {
    return $this->users[$name];
  }

}
