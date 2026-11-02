<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Mailer;

use Drupal\node\NodeInterface;
use Drupal\symfony_mailer\Component\ComponentMailerInterface;
use Drupal\user\UserInterface;

/**
 * Mails zu Bulletins (Typ tannwald_bulletin, Subtyp notify).
 */
interface BulletinMailerInterface extends ComponentMailerInterface {

  /**
   * Benachrichtigt einen Empfänger über ein Bulletin mit Bestätigungspflicht.
   *
   * @param \Drupal\node\NodeInterface $bulletin
   *   Das Bulletin.
   * @param \Drupal\user\UserInterface $recipient
   *   Empfänger; Sprache = preferred_langcode.
   * @param \Drupal\node\NodeInterface[] $machines
   *   Betroffene Maschinen des Empfängers.
   *
   * @return bool
   *   Ob der Versand gelang.
   */
  public function notify(NodeInterface $bulletin, UserInterface $recipient, array $machines): bool;

}
