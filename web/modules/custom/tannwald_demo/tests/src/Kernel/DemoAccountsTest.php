<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_demo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\tannwald_demo\DisplayNames;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nachlieferung fehlender Demo-Konten (tw2-12, DemoAccounts + Deploy-Hook).
 *
 * Ohne Fixture-Migration: Konten entstehen direkt aus fixtures/sap/nutzer.json.
 */
#[Group('tannwald_demo')]
final class DemoAccountsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'options', 'file', 'node', 'taxonomy', 'workflows', 'content_moderation',
    'language', 'migrate', 'migrate_plus', 'migrate_tools', 'tannwald_access', 'tannwald_demo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'language']);
    // Sprache de wie auf der Site; ohne sie fiele das Konto auf en zurück.
    ConfigurableLanguage::createFromLangcode('de')->save();
    foreach (['kunde', 'haendler', 'techniker', 'redaktion', 'compliance', 'portal_admin'] as $role) {
      Role::create(['id' => $role, 'label' => $role])->save();
    }
    // Uid 1 belegt der Platzhalter admin (kein Demo-Konto).
    User::create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1])->save();
  }

  /**
   * Konto nach Name oder NULL.
   */
  protected function load(string $name): ?UserInterface {
    $account = current($this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['name' => $name]));
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Fehlende Konten, Nachlieferung, zweiter Lauf ohne Änderung, Deploy-Hook.
   */
  public function testEnsureCreatesMissingAccountsOnce(): void {
    /** @var \Drupal\tannwald_demo\DemoAccounts $accounts */
    $accounts = $this->container->get('tannwald_demo.accounts');

    // Leere Site: alle sieben fehlen, der Satz ist nicht begonnen.
    $this->assertSame(DisplayNames::names(), $accounts->missing());
    $this->assertFalse($accounts->started());

    // Deploy-Hook auf einer Site ohne Demo-Satz: nichts anlegen (Gegenprobe).
    \Drupal::moduleHandler()->loadInclude('tannwald_demo', 'php', 'tannwald_demo.deploy');
    putenv('TANNWALD_DEMO=1');
    $sandbox = [];
    $message = tannwald_demo_deploy_demo_accounts($sandbox);
    $this->assertStringContainsString('none seeded yet', $message);
    $this->assertNull($this->load('portal-admin'));

    // Begonnener Satz (wie auf dem Demo-Server vor tw2-12): sechs Konten da.
    foreach (['kunde-a', 'kunde-b', 'haendler', 'techniker', 'redaktion', 'compliance'] as $name) {
      User::create(['name' => $name, 'mail' => $name . '@tannwald.example', 'status' => 1])->save();
    }
    $this->assertSame(['portal-admin'], $accounts->missing());
    $this->assertTrue($accounts->started());

    // Ausserhalb einer Demo-Umgebung tut der Hook nichts (Gegenprobe).
    putenv('TANNWALD_DEMO');
    putenv('TANNWALD_DEPLOY');
    putenv('IS_DDEV_PROJECT');
    putenv('CI');
    $this->assertStringContainsString('not a demo environment', tannwald_demo_deploy_demo_accounts($sandbox));
    $this->assertNull($this->load('portal-admin'));

    // deploy.sh startet drush deploy mit TANNWALD_DEPLOY=1: Konto entsteht.
    putenv('TANNWALD_DEPLOY=1');
    $this->assertStringContainsString('created portal-admin', tannwald_demo_deploy_demo_accounts($sandbox));
    $portalAdmin = $this->load('portal-admin');
    $this->assertInstanceOf(UserInterface::class, $portalAdmin);
    $this->assertSame('portal-admin@tannwald.example', $portalAdmin->getEmail());
    $this->assertSame(['authenticated', 'portal_admin'], $portalAdmin->getRoles());
    $this->assertTrue($portalAdmin->isActive());
    $this->assertSame('de', $portalAdmin->getPreferredLangcode());
    $this->assertTrue((bool) $this->container->get('user.auth')->authenticateAccount($portalAdmin, 'demo'));
    $this->assertSame('Jonas Widmer', $portalAdmin->getDisplayName());

    // Zweiter Lauf: nichts fehlt, nichts entsteht.
    $this->assertSame([], $accounts->missing());
    $this->assertSame([], $accounts->ensure());
    $this->assertStringContainsString('all 7 present', tannwald_demo_deploy_demo_accounts($sandbox));
    $this->assertCount(8, User::loadMultiple(), 'Seven demo accounts and admin.');
  }

}
