<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest;

/**
 * Slug für Kapitelanker (id="kapitel-<slug>") aus einer Überschrift.
 *
 * Regel (RunBook §1.1): NFC, Kleinbuchstaben, ä ö ü ß → ae oe ue ss, Akzente
 * entfernt, alles ausser [a-z0-9] → „-“, gestutzt und verdichtet.
 * Beispiel: „9.4 Störungen an der Siegelstation“ →
 * „9-4-stoerungen-an-der-siegelstation“.
 */
final class HeadingSlug {

  /**
   * Feste Ersetzungen vor dem Entfernen der Akzente.
   */
  private const UMLAUTS = [
    'ä' => 'ae',
    'ö' => 'oe',
    'ü' => 'ue',
    'ß' => 'ss',
    'ẞ' => 'ss',
    'æ' => 'ae',
    'œ' => 'oe',
    'ø' => 'o',
    'đ' => 'd',
    'ð' => 'd',
    'ł' => 'l',
    'þ' => 'th',
    'ı' => 'i',
  ];

  /**
   * Akzentbuchstaben ohne intl (Weg ohne Normalizer, gleiche Ergebnisse).
   */
  private const ACCENTS = [
    'a' => 'àáâãåāăą',
    'c' => 'çćĉċč',
    'd' => 'ď',
    'e' => 'èéêëēĕėęě',
    'g' => 'ĝğġģ',
    'h' => 'ĥħ',
    'i' => 'ìíîïĩīĭįİ',
    'j' => 'ĵ',
    'k' => 'ķ',
    'l' => 'ĺļľŀ',
    'n' => 'ñńņňŉ',
    'o' => 'òóôõōŏő',
    'r' => 'ŕŗř',
    's' => 'śŝşšș',
    't' => 'ţťŧț',
    'u' => 'ùúûũūŭůűų',
    'w' => 'ŵ',
    'y' => 'ýÿŷ',
    'z' => 'źżž',
  ];

  /**
   * Liefert den Slug einer Überschrift.
   */
  public static function of(string $heading): string {
    return self::slug($heading, class_exists(\Normalizer::class));
  }

  /**
   * Slug mit wählbarem Weg (intl oder feste Tabelle) — für den Gleichheitstest.
   *
   * @internal
   */
  public static function slug(string $heading, bool $useIntl): string {
    if ($useIntl) {
      $heading = (string) \Normalizer::normalize($heading, \Normalizer::FORM_C);
    }
    $s = mb_strtolower($heading, 'UTF-8');
    $s = strtr($s, self::UMLAUTS);
    if ($useIntl) {
      // Zerlegen und kombinierende Zeichen (Akzente) entfernen.
      $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_D);
      $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
    }
    else {
      // Ohne intl: kombinierende Zeichen weg, Akzentbuchstaben per Tabelle.
      $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
      $map = [];
      foreach (self::ACCENTS as $base => $chars) {
        foreach (mb_str_split($chars, 1, 'UTF-8') as $char) {
          $map[$char] = $base;
        }
      }
      $s = strtr($s, $map);
    }
    $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
  }

}
