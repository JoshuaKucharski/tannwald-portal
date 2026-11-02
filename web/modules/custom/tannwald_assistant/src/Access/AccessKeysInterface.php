<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Access;

use Drupal\Core\Session\AccountInterface;

/**
 * Liefert die Access-Keys eines Kontos für den Chunk-Store-Filter.
 *
 * Naht zum EntitlementService von tannwald_access (final, darum nicht direkt
 * doublebar); die einzige Implementierung delegiert 1:1.
 */
interface AccessKeysInterface {

  /**
   * Keys der Form <basis>+export:<klasse>; leer = keine Treffer.
   *
   * @return string[]
   *   Keys wie EntitlementService::getRealmsForAccount().
   */
  public function forAccount(AccountInterface $account): array;

}
