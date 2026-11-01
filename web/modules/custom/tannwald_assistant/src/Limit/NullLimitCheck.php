<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Limit;

use Drupal\Core\Session\AccountInterface;

/**
 * Null-Implementierung bis 4.2: es gibt kein Limit.
 */
final class NullLimitCheck implements LimitCheckInterface {

  /**
   * {@inheritdoc}
   */
  public function allowsAnswer(AccountInterface $account): bool {
    return TRUE;
  }

}
