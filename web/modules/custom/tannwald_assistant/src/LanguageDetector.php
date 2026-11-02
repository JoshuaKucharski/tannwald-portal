<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

/**
 * Erkennt de, en oder fr über Stoppwörter (Pflichtenheft §6.3).
 *
 * Tokenisierung auf Kleinbuchstaben (Unicode), Trenner sind alle Zeichen
 * ausser Buchstaben, Ziffern und Bindestrich; Apostrophe trennen (l'appareil →
 * l, appareil). Weniger als drei Wörter oder kein eindeutiger Sieger →
 * Rückfall (Sprache des Nutzers). Ergebnis ist immer de, en oder fr; ein
 * Rückfall ausserhalb davon wird zu de (Standardsprache der Site).
 */
final class LanguageDetector {

  public const MIN_WORDS = 3;

  /**
   * Stoppwörter je Sprache (je mindestens 40).
   */
  public const STOPWORDS = [
    'de' => [
      'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem',
      'einer', 'und', 'oder', 'aber', 'nicht', 'kein', 'keine', 'ist', 'sind',
      'wird', 'werden', 'wurde', 'muss', 'müssen', 'kann', 'können', 'soll',
      'wie', 'was', 'wann', 'warum', 'wo', 'welche', 'welcher', 'welches',
      'ich', 'wir', 'sie', 'mit', 'für', 'auf', 'aus', 'bei', 'nach', 'von',
      'zum', 'zur', 'im', 'ins', 'vom', 'beim', 'oft', 'wieviel', 'wieso',
      'darf', 'gibt', 'habe', 'hat', 'mein', 'meine', 'unsere', 'ob', 'dass',
    ],
    'en' => [
      'the', 'a', 'an', 'and', 'or', 'but', 'not', 'no', 'is', 'are', 'was',
      'were', 'be', 'been', 'do', 'does', 'did', 'how', 'what', 'when', 'why',
      'where', 'which', 'who', 'can', 'could', 'should', 'must', 'will',
      'would', 'i', 'we', 'you', 'my', 'our', 'with', 'for', 'on', 'of', 'to',
      'from', 'at', 'by', 'this', 'that', 'these', 'those', 'it', 'its',
      'have', 'has', 'often', 'there', 'any', 'much', 'many', 'into', 'if',
    ],
    'fr' => [
      'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou', 'mais',
      'ne', 'pas', 'est', 'sont', 'être', 'faut', 'doit', 'doivent', 'peut',
      'peuvent', 'comment', 'quand', 'pourquoi', 'où', 'quel', 'quelle',
      'quels', 'quelles', 'qui', 'que', 'quoi', 'je', 'nous', 'vous', 'il',
      'elle', 'avec', 'pour', 'sur', 'dans', 'par', 'au', 'aux', 'ce', 'cette',
      'ces', 'mon', 'ma', 'mes', 'notre', 'nos', 'souvent', 'combien', 'l',
      'd', 'qu', 'j', 'à', 'y', 'se', 'faire',
    ],
  ];

  /**
   * Erkennt die Sprache eines Textes.
   *
   * @param string $text
   *   Frage oder Satz.
   * @param string $fallback
   *   Sprache des Nutzers (preferred_langcode).
   *
   * @return string
   *   de, en oder fr.
   */
  public function detect(string $text, string $fallback): string {
    $fallback = in_array($fallback, AnswerTexts::LANGCODES, TRUE) ? $fallback : AnswerTexts::DEFAULT_LANGCODE;
    $tokens = self::tokenize($text);
    if (count($tokens) < self::MIN_WORDS) {
      return $fallback;
    }
    $scores = [];
    foreach (self::STOPWORDS as $langcode => $words) {
      $lookup = array_flip($words);
      $scores[$langcode] = 0;
      foreach ($tokens as $token) {
        if (isset($lookup[$token])) {
          $scores[$langcode]++;
        }
      }
    }
    $best = max($scores);
    if ($best === 0) {
      return $fallback;
    }
    $winners = array_keys($scores, $best, TRUE);
    if (count($winners) === 1) {
      return $winners[0];
    }
    // Gleichstand: Nutzersprache, wenn sie dabei ist; sonst Rückfall.
    return $fallback;
  }

  /**
   * Zerlegt einen Text in Wörter (Kleinbuchstaben).
   *
   * @return string[]
   *   Wörter.
   */
  public static function tokenize(string $text): array {
    $text = mb_strtolower($text, 'UTF-8');
    $parts = preg_split('/[^\p{L}\p{N}\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($parts, static fn (string $part): bool => trim($part, '-') !== ''));
  }

}
