<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Zugriff auf support_anfrage: eigene lesen; Redaktion, Compliance, Admin alle.
 *
 * Anlegen nur über die API (dort geprüft), Ändern und Löschen nur mit
 * "administer tannwald assistant" (Status-Wechsel kommt in 6.1).
 */
final class SupportAnfrageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * Rollen, die alle Support-Anfragen sehen.
   */
  public const STAFF_ROLES = ['redaktion', 'compliance', 'portal_admin'];

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin = AccessResult::allowedIfHasPermission($account, 'administer tannwald assistant');
    if ($operation !== 'view') {
      return $admin;
    }
    $staff = AccessResult::allowedIf(array_intersect($account->getRoles(), self::STAFF_ROLES) !== [])->cachePerUser();
    $own = AccessResult::allowedIf($entity instanceof EntityOwnerInterface && (int) $entity->getOwnerId() === (int) $account->id() && $account->isAuthenticated())
      ->cachePerUser()
      ->addCacheableDependency($entity);
    return $admin->orIf($staff)->orIf($own);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Die Oberfläche legt keine an; die API prüft selbst (eigener Log-Eintrag).
    return AccessResult::allowedIfHasPermission($account, 'administer tannwald assistant');
  }

}
