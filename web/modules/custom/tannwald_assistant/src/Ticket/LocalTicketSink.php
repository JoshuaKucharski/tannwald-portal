<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Ticket;

use Drupal\tannwald_assistant\Entity\SupportAnfrage;

/**
 * PoC-Ticketsystem: speichert die support_anfrage lokal, sonst nichts.
 */
final class LocalTicketSink implements TicketSinkInterface {

  /**
   * {@inheritdoc}
   */
  public function create(SupportAnfrage $supportAnfrage): string {
    if ($supportAnfrage->isNew()) {
      $supportAnfrage->save();
    }
    return (string) $supportAnfrage->id();
  }

}
