<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Limit;

use Drupal\Core\Session\AccountInterface;

/**
 * Prüft vor jeder Antwort das Monatslimit (Naht für den LimitService in 4.2).
 *
 * 4.2 ersetzt nur die Klasse des Dienstes tannwald_assistant.limit_check;
 * AnswerService bleibt unverändert.
 */
interface LimitCheckInterface {

  /**
   * TRUE, wenn der Assistent für dieses Konto jetzt antworten darf.
   *
   * FALSE führt zu status "limit" (HTTP 503 in 4.2, Suche bleibt erreichbar).
   */
  public function allowsAnswer(AccountInterface $account): bool;

}
