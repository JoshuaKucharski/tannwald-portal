<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\tannwald_access\EntitlementService;

/**
 * Access-Keys aus tannwald_access.entitlement (eine Quelle für alles).
 */
final class EntitlementAccessKeys implements AccessKeysInterface {

  public function __construct(
    private readonly EntitlementService $entitlement,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function forAccount(AccountInterface $account): array {
    return array_values(array_unique(array_map('strval', $this->entitlement->getRealmsForAccount($account))));
  }

}
