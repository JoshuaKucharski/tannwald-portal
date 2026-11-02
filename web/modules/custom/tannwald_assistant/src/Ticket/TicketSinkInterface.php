<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Ticket;

use Drupal\tannwald_assistant\Entity\SupportAnfrage;

/**
 * Naht zum Ticketsystem (Pflichtenheft §7: produktiv Salesforce-REST-Client).
 */
interface TicketSinkInterface {

  /**
   * Übergibt eine Support-Anfrage und liefert die Ticket-Referenz.
   *
   * @param \Drupal\tannwald_assistant\Entity\SupportAnfrage $supportAnfrage
   *   Neue oder gespeicherte Anfrage (Frage, Verlauf, Maschine, Nutzer).
   *
   * @return string
   *   Referenz im Zielsystem; im PoC die ID der support_anfrage.
   */
  public function create(SupportAnfrage $supportAnfrage): string;

}
