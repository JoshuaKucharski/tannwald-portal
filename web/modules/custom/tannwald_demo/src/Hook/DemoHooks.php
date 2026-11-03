<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
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
    // Nachtrag 8 (F14): auch Name, Status und Rollen der Demo-Konten sind fest
    // (nur uid 1 darf sie ändern); Konto löschen verbietet userAccess().
    foreach (['mail', 'pass', 'current_pass', 'name', 'status', 'roles'] as $key) {
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
   * Implements hook_ENTITY_TYPE_access() for user.
   *
   * Nachtrag 8 (F14, tw2-12): uid 1 (admin) darf nur uid 1 selbst bearbeiten
   * oder löschen — auch mit „administer users“ (ein Demo-Konto mit Passwort
   * demo darf den Betreiber nie aussperren). Demo-Konten (DisplayNames) darf
   * niemand ausser uid 1 löschen (user_cancel); forbidden() schlägt jede
   * Erlaubnis des Zugriffs-Handlers. Alles andere bleibt neutral.
   */
  #[Hook('user_access')]
  public function userAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof UserInterface || !in_array($operation, ['update', 'delete'], TRUE)) {
      return AccessResult::neutral();
    }
    $result = AccessResult::neutral()->cachePerUser()->addCacheableDependency($entity);
    if ((int) $account->id() === 1) {
      return $result;
    }
    if ((int) $entity->id() === 1) {
      return AccessResult::forbidden('Only uid 1 may edit or delete uid 1.')->cachePerUser()->addCacheableDependency($entity);
    }
    if ($operation === 'delete' && DisplayNames::isDemoAccount((string) $entity->getAccountName())) {
      return AccessResult::forbidden('Demo accounts cannot be cancelled.')->cachePerUser()->addCacheableDependency($entity);
    }
    return $result;
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
