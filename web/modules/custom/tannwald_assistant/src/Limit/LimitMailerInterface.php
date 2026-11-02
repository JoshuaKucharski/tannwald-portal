<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Limit;

use Drupal\user\UserInterface;

/**
 * Warnmail bei 80 % des Monatslimits (FA-REP-02, TC-12).
 */
interface LimitMailerInterface {

  /**
   * Schickt die Warnung an einen Empfänger.
   *
   * @param \Drupal\user\UserInterface $recipient
   *   Konto mit Rolle portal_admin.
   * @param int $usage
   *   Verbrauchte Tokens im Monat.
   * @param int $limit
   *   Monatslimit.
   *
   * @return bool
   *   TRUE, wenn versendet.
   */
  public function warn(UserInterface $recipient, int $usage, int $limit): bool;

}
