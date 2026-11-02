<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Zugriff auf Lesebestätigungen: ansehen ja, ändern und löschen nie.
 *
 * Ansehen dürfen der eigene Nutzer, Rollen mit "tannwald view audit log"
 * sowie redaktion und portal_admin. Update und Delete sind für alle verboten,
 * auch für uid 1 (kein admin_permission-Bypass); Anlegen nur per Code über
 * ConfirmationService.
 */
class LesebestaetigungAccessControlHandler extends EntityAccessControlHandler {

  /**
   * Rollen mit Leserecht auf alle Bestätigungen.
   */
  public const READER_ROLES = ['redaktion', 'portal_admin'];

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view') {
      return AccessResult::forbidden('Read confirmations are immutable.');
    }
    $own = $entity instanceof EntityOwnerInterface && $account->isAuthenticated() && (int) $entity->getOwnerId() === (int) $account->id();
    return AccessResult::allowedIf($own)->cachePerUser()->addCacheableDependency($entity)
      ->orIf(AccessResult::allowedIfHasPermission($account, 'tannwald view audit log'))
      ->orIf(AccessResult::allowedIf(array_intersect(self::READER_ROLES, $account->getRoles()) !== [])->addCacheContexts(['user.roles']));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::forbidden('Read confirmations are created by the confirmation service only.');
  }

}
