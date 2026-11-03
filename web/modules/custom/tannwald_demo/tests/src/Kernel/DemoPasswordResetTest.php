<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_demo\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\Form\UserPasswordForm;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Passwort-Reset (tw2-12 Nachtrag 9): keine Mail für uid 1 und administrator.
 *
 * Demo-Konten bekommen die Mail weiter; der One-Time-Login schaltet bei ihnen
 * das Passwortfeld nicht frei (Sperre aus Nachtrag 2 hält auch mit
 * user_pass_reset). Gegenprobe: ein gewöhnliches Konto bekommt die Mail.
 */
#[Group('tannwald_demo')]
final class DemoPasswordResetTest extends KernelTestBase {

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
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('mail', 'portal@tannwald.example')->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $accounts = [
      'admin' => ['administrator'],
      'chef' => ['administrator'],
      'kunde-a' => [],
      'gast' => [],
    ];
    foreach ($accounts as $name => $roles) {
      User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1, 'roles' => $roles])->save();
    }
  }

  /**
   * Sendet „Passwort vergessen?“ für eine Eingabe und zählt die Mails.
   */
  protected function requestReset(string $identifier): int {
    \Drupal::state()->set('system.test_mail_collector', []);
    $form_state = new FormState();
    $form_state->setValues(['name' => $identifier]);
    \Drupal::formBuilder()->submitForm(UserPasswordForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'Formular ohne Fehler: ' . $identifier);
    $mails = \Drupal::state()->get('system.test_mail_collector') ?: [];
    return count($mails);
  }

  /**
   * Keine Mail für uid 1 und administrator, Mail für Demo- und Fremdkonto.
   */
  public function testProtectedAccountsGetNoResetMail(): void {
    $this->assertSame(0, $this->requestReset('admin'), 'uid 1: keine Mail');
    $this->assertSame(0, $this->requestReset('admin@example.com'), 'uid 1 per E-Mail: keine Mail');
    $this->assertSame(0, $this->requestReset('chef'), 'administrator: keine Mail');
    // Gegenproben.
    $this->assertSame(1, $this->requestReset('gast'), 'gewöhnliches Konto: eine Mail');
    $this->assertSame(1, $this->requestReset('kunde-a'), 'Demo-Konto: eine Mail (gesperrtes Formular)');
    // Dieselbe neutrale Meldung in allen Fällen (Datenschutz, kein Hinweis auf
    // das Konto).
    $messages = \Drupal::messenger()->all()['status'] ?? [];
    $this->assertCount(5, $messages);
    foreach ($messages as $message) {
      $this->assertStringContainsString('If', (string) $message);
      $this->assertStringContainsString('is a valid account', (string) $message);
    }
  }

  /**
   * One-Time-Login (user_pass_reset) schaltet bei Demo-Konten nichts frei.
   */
  public function testResetTokenDoesNotUnlockDemoAccount(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $kundeA = current($storage->loadByProperties(['name' => 'kunde-a']));
    $gast = current($storage->loadByProperties(['name' => 'gast']));
    $this->assertInstanceOf(UserInterface::class, $kundeA);
    $this->assertInstanceOf(UserInterface::class, $gast);
    $builder = $this->container->get('entity.form_builder');
    // Demo-Konto nach One-Time-Login: Passwort und Mail bleiben versteckt.
    $this->container->get('current_user')->setAccount($kundeA);
    $form = $builder->getForm($kundeA, 'default', ['user_pass_reset' => TRUE]);
    $this->assertFalse($form['account']['pass']['#access'], 'pass bleibt gesperrt');
    $this->assertFalse($form['account']['mail']['#access'], 'mail bleibt gesperrt');
    // Gegenprobe: ein gewöhnliches Konto darf nach dem One-Time-Login das
    // Passwort ohne aktuelles Passwort setzen.
    $this->container->get('current_user')->setAccount($gast);
    $form = $builder->getForm($gast, 'default', ['user_pass_reset' => TRUE]);
    $this->assertTrue($form['account']['pass']['#access'] ?? TRUE, 'pass offen');
    $this->assertFalse($form['account']['current_pass']['#access'], 'current_pass nach Reset nicht nötig');
  }

}
