<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin;

use Drupal\node\NodeInterface;

/**
 * Bulletin-Kennung und Titel aus dem Node-Titel.
 *
 * Das Datenmodell hat kein eigenes Feld für die Kennung (SB-2026-07); Vertrag
 * an den Seeder 2.3: Node-Titel "<Kennung> <Titel>", z. B. "SB-2026-07
 * Schutzhaube Siegelstation" (Trenner "·", ":" oder "–" sind erlaubt).
 */
final class BulletinLabel {

  /**
   * Muster der Kennung.
   */
  public const PATTERN = '/^\s*([A-Z]{2,4}-\d{4}-\d{2,3})\s*(?:[·:–—-]\s*)?(.*)$/u';

  /**
   * Zerlegt den Titel.
   *
   * @return array{id: string, titel: string}
   *   Kennung (ohne Muster: "#<nid>") und Titel (ohne Muster: ganzer Titel).
   */
  public static function of(NodeInterface $bulletin): array {
    $label = trim((string) $bulletin->label());
    if (preg_match(self::PATTERN, $label, $match)) {
      return ['id' => $match[1], 'titel' => trim($match[2])];
    }
    return ['id' => '#' . $bulletin->id(), 'titel' => $label];
  }

}
