<?php

declare(strict_types=1);

namespace Drupal\tannwald_qwen;

/**
 * Entfernt Schlüssel und schlüsselähnliche Zeichenfolgen aus Meldungen.
 *
 * Jede Meldung, die aus einer API-Antwort stammt, läuft hier durch, bevor sie
 * in ein Log, eine Exception oder eine Ausgabe gelangt. Der echte Schlüssel
 * wird zusätzlich wörtlich ersetzt, falls der Aufrufer ihn kennt.
 */
final class SecretScrubber {

  /**
   * Ersatztext für jede entfernte Zeichenfolge.
   */
  public const MASK = '[redacted]';

  /**
   * Muster für Schlüssel und schlüsselähnliche Folgen.
   *
   * Sk-Schlüssel (auch teilmaskiert), Bearer-Token, lange Hex-Folgen (ab 20)
   * und Token-Folgen (ab 32 Zeichen).
   */
  public const PATTERNS = [
    '/\bsk-[A-Za-z0-9*_\-.]{4,}/',
    '/\bBearer\s+[^\s"\',;]+/i',
    '/\b[A-Fa-f0-9]{20,}\b/',
    '/\b[A-Za-z0-9_\-]{32,}\b/',
  ];

  /**
   * Gibt den Text ohne Schlüssel zurück.
   *
   * @param string $text
   *   Die Meldung.
   * @param array<int, string|null> $secrets
   *   Bekannte Geheimnisse, die wörtlich ersetzt werden (ab 8 Zeichen).
   */
  public static function scrub(string $text, array $secrets = []): string {
    foreach ($secrets as $secret) {
      if (is_string($secret) && strlen($secret) >= 8) {
        $text = str_replace($secret, self::MASK, $text);
      }
    }
    return (string) preg_replace(self::PATTERNS, self::MASK, $text);
  }

}
