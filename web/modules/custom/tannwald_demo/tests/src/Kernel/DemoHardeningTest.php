<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_demo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Härtung der Demo-Konten (tw2-12 Nachtrag 8, F14).
 *
 * Das Konto portal-admin darf uid 1 weder bearbeiten noch löschen, Demo-Konten
 * nicht löschen und sieht Name/Status/Rollen der Demo-Konten nicht im Formular —
 * selbst mit „administer users“ (die Rolle hat es seit Nachtrag 8 nicht mehr,
 * der Hook hält auch dann). Gegenproben: admin (uid 1) darf alles, ein Konto
 * ausserhalb der Liste ist nicht geschützt.
 */
#[Group('tannwald_demo')]
final class DemoHardeningTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'options', 'file', 'node', 'taxonomy', 'workflows', 'content_moderation',
    'migrate', 'migrate_plus', 'migrate_tools', 'tannwald_access', 'tannwald_demo',
  ];

  /**
   * Konten des Tests.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  protected array $users = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    // Absichtlich mit „administer users/permissions“ (Rollen-Feld): der Hook
    // und die Sperre müssen auch dann halten.
    $role = Role::create(['id' => 'portal_admin', 'label' => 'Portal-Admin']);
    $role->grantPermission('administer users');
    $role->grantPermission('administer permissions');
    $role->save();
    $this->users['admin'] = $this->createAccount('admin', ['administrator']);
    $this->users['portal-admin'] = $this->createAccount('portal-admin', ['portal_admin']);
    $this->users['kunde-a'] = $this->createAccount('kunde-a', []);
    $this->users['gast'] = $this->createAccount('gast', []);
    $this->assertSame(1, (int) $this->users['admin']->id(), 'admin ist uid 1');
  }

  /**
   * Legt ein aktives Konto an.
   *
   * @param string $name
   *   Kontoname.
   * @param string[] $roles
   *   Rollen.
   */
  protected function createAccount(string $name, array $roles): UserInterface {
    $account = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1, 'roles' => $roles]);
    $account->save();
    return $account;
  }

  /**
   * Nutzerformular eines Kontos aus Sicht eines Bearbeiters.
   *
   * @return array<string, mixed>
   *   Formular.
   */
  protected function form(UserInterface $account, UserInterface $viewer): array {
    $this->container->get('current_user')->setAccount($viewer);
    return $this->container->get('entity.form_builder')->getForm($account, 'default');
  }

  /**
   * Zugriff eines Kontos auf ein anderes ohne Cache.
   */
  protected function allowed(UserInterface $target, string $operation, UserInterface $viewer): bool {
    $this->container->get('entity_type.manager')->getAccessControlHandler('user')->resetCache();
    return $target->access($operation, $viewer);
  }

  /**
   * Schutz von uid 1, Demo-Konten nicht löschbar, Felder versteckt; Gegenproben.
   */
  public function testHardening(): void {
    $admin = $this->users['admin'];
    $portalAdmin = $this->users['portal-admin'];
    $kundeA = $this->users['kunde-a'];
    $gast = $this->users['gast'];

    // (1) uid 1: portal-admin darf weder bearbeiten noch löschen, trotz
    // administer users; admin selbst darf.
    $this->assertFalse($this->allowed($admin, 'update', $portalAdmin), 'portal-admin darf uid 1 nicht bearbeiten');
    $this->assertFalse($this->allowed($admin, 'delete', $portalAdmin), 'portal-admin darf uid 1 nicht löschen');
    $this->assertTrue($this->allowed($admin, 'update', $admin), 'admin bearbeitet sich selbst');

    // (2) Demo-Konto: nicht löschbar (user_cancel), aber bearbeitbar; admin
    // darf löschen. Gegenprobe: ein Konto ausserhalb der Liste bleibt mit
    // administer users löschbar.
    $this->assertFalse($this->allowed($kundeA, 'delete', $portalAdmin), 'kunde-a nicht löschbar');
    $this->assertTrue($this->allowed($kundeA, 'update', $portalAdmin), 'kunde-a bearbeitbar (Felder versteckt)');
    $this->assertTrue($this->allowed($kundeA, 'delete', $admin), 'admin darf kunde-a löschen');
    $this->assertTrue($this->allowed($gast, 'delete', $portalAdmin), 'gast ist kein Demo-Konto');
    $this->assertFalse($this->allowed($admin, 'update', $gast), 'auch gast darf uid 1 nicht bearbeiten');

    // (2) Formular: Name, Status, Rollen (und Mail/Passwort) beim Demo-Konto
    // weg, beim fremden Konto da; uid 1 sieht alles.
    $form = $this->form($kundeA, $portalAdmin);
    foreach (['name', 'status', 'roles', 'mail', 'pass'] as $key) {
      $this->assertArrayHasKey($key, $form['account'], $key);
      $this->assertFalse($form['account'][$key]['#access'], $key . ' versteckt');
    }
    $form = $this->form($gast, $portalAdmin);
    foreach (['name', 'status', 'roles', 'mail', 'pass'] as $key) {
      $this->assertTrue($form['account'][$key]['#access'] ?? TRUE, $key . ' offen bei gast');
    }
    $form = $this->form($kundeA, $admin);
    foreach (['name', 'status', 'roles', 'mail', 'pass'] as $key) {
      $this->assertTrue($form['account'][$key]['#access'] ?? TRUE, $key . ' offen für uid 1');
    }
  }

}
