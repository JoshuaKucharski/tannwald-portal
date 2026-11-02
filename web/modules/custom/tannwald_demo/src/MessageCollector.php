<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

use Drupal\migrate\MigrateMessageInterface;

/**
 * Sammelt Migrate-Meldungen für die Ausgabe von demo-install.
 */
final class MessageCollector implements MigrateMessageInterface {

  /**
   * Gesammelte Meldungen "<typ>: <text>" (ohne Status-Zeilen).
   *
   * @var string[]
   */
  public array $messages = [];

  /**
   * {@inheritdoc}
   */
  public function display($message, $type = 'status') {
    // Fortschrittszeilen von migrate_tools ("Processed 7 items …") sind
    // keine Meldungen; die Zählung kommt aus dem Bericht.
    if ($type === 'status') {
      return;
    }
    $this->messages[] = $type . ': ' . $message;
  }

}
