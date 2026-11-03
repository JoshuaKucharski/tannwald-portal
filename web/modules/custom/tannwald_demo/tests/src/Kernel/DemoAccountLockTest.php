<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_demo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sperre für Passwort und E-Mail der Demo-Konten (tw2-12, Nachtrag 2).
 */
#[Group('tannwald_demo')]
final class DemoAccountLockTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'options', 'file', 'node', 'taxonomy', 'workflows', 'content_moderation',
    'migrate', 'migrate_plus', 'migrate_tools', 'tannwald_access', 'tannwald_demo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    User::create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1])->save();
  }

  /**
   * Nutzerformular eines Kontos als angemeldeter Nutzer.
   *
   * @return array<string, mixed>
   *   Formular.
   */
  protected function form(UserInterface $account, UserInterface $viewer): array {
    $this->container->get('current_user')->setAccount($viewer);
    return $this->container->get('entity.form_builder')->getForm($account, 'default');
  }

  /**
   * Demo-Konto gesperrt, fremdes Konto frei, admin frei (Gegenproben).
   */
  public function testDemoAccountFieldsAreLocked(): void {
    $demo = User::create(['name' => 'kunde-a', 'mail' => 'kunde-a@tannwald.example', 'status' => 1]);
    $demo->save();
    $other = User::create(['name' => 'gast', 'mail' => 'gast@example.com', 'status' => 1]);
    $other->save();
    $admin = User::load(1);

    // Demo-Konto bearbeitet sich selbst: Mail, Passwort und aktuelles
    // Passwort sind weg, der Hinweis steht.
    $form = $this->form($demo, $demo);
    foreach (['mail', 'pass', 'current_pass'] as $key) {
      $this->assertArrayHasKey($key, $form['account'], $key);
      $this->assertFalse($form['account'][$key]['#access'], $key . ' locked');
    }
    $this->assertSame('item', $form['account']['demo_lock']['#type']);
    $this->assertStringContainsString('fixed', (string) $form['account']['demo_lock']['#markup']);

    // Gegenprobe 1: ein Konto ausserhalb der Liste bleibt frei.
    $form = $this->form($other, $other);
    foreach (['mail', 'pass', 'current_pass'] as $key) {
      $this->assertArrayHasKey($key, $form['account'], $key);
      $this->assertTrue($form['account'][$key]['#access'] ?? TRUE, $key . ' open');
    }
    $this->assertArrayNotHasKey('demo_lock', $form['account']);

    // Gegenprobe 2: uid 1 darf ein Demo-Konto weiterhin pflegen.
    $form = $this->form($demo, $admin);
    $this->assertTrue($form['account']['mail']['#access'] ?? TRUE, 'admin may edit');
    $this->assertArrayNotHasKey('demo_lock', $form['account']);
  }

}
