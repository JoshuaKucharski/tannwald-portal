<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Lesebestätigung: ein Nutzer bestätigt ein Bulletin für eine Maschine.
 */
interface LesebestaetigungInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * NID des Bulletins.
   */
  public function getBulletinId(): int;

  /**
   * NID der Maschine.
   */
  public function getMachineId(): int;

  /**
   * Zeitpunkt der Bestätigung (Unix).
   */
  public function getConfirmedTime(): int;

  /**
   * Land des Nutzers zum Zeitpunkt der Bestätigung (ISO alpha-2) oder NULL.
   */
  public function getCountry(): ?string;

}
