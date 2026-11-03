<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tannwald_demo\DemoInstaller;
use Drupal\tannwald_demo\DisplayNames;
use Drupal\tannwald_demo\FixturePath;
use Drupal\user\UserInterface;

/**
 * Hooks des Seeders.
 */
final class DemoHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_migration_plugins_alter().
   *
   * Relative Quell-URLs der Demo-Migrationen zeigen auf fixtures/ im
   * Repo-Stamm (DRUPAL_ROOT/../fixtures).
   */
  #[Hook('migration_plugins_alter')]
  public function migrationPluginsAlter(array &$migrations): void {
    foreach (DemoInstaller::MIGRATIONS as $id) {
      if (!isset($migrations[$id]['source']['urls'])) {
        continue;
      }
      $migrations[$id]['source']['urls'] = array_map(
        [FixturePath::class, 'resolve'],
        (array) $migrations[$id]['source']['urls'],
      );
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for user_form.
   *
   * Nachtrag 2 von tw2-12: Die sieben Demo-Konten (DisplayNames::ACCOUNTS)
   * teilen sich das Passwort demo und ihre Mail-Adressen; wer angemeldet ist,
   * darf Passwort und E-Mail dieser Konten nicht ändern, sonst sperrt ein
   * Besucher die Demo für alle. Uid 1 (admin) bleibt frei, damit der
   * Betreiber eingreifen kann; andere Konten sind nicht betroffen.
   */
  #[Hook('form_user_form_alter')]
  public function formUserFormAlter(array &$form, FormStateInterface $form_state): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface) {
      return;
    }
    $account = $formObject->getEntity();
    if (!$account instanceof UserInterface || !DisplayNames::isDemoAccount((string) $account->getAccountName())) {
      return;
    }
    if ((int) $this->currentUser->id() === 1) {
      return;
    }
    foreach (['mail', 'pass', 'current_pass'] as $key) {
      if (isset($form['account'][$key])) {
        $form['account'][$key]['#access'] = FALSE;
      }
    }
    $form['account']['demo_lock'] = [
      '#type' => 'item',
      '#markup' => $this->t('Demo account: the e-mail address and the password (demo) are fixed and cannot be changed here.'),
      '#weight' => -20,
    ];
  }

  /**
   * Implements hook_user_format_name_alter().
   */
  #[Hook('user_format_name_alter')]
  public function userFormatNameAlter(string &$name, AccountInterface $account): void {
    $display = DisplayNames::of((string) $account->getAccountName());
    if ($display !== NULL) {
      $name = $display;
    }
  }

}
