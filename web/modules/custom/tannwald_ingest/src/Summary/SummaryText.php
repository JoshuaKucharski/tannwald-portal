<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Summary;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;

/**
 * Säubert die Modellantwort: erlaubte Tags, Wortgrenze, Sprachprüfung.
 */
final class SummaryText {

  /**
   * Tags, die eine Zusammenfassung enthalten darf.
   */
  public const ALLOWED_TAGS = ['p', 'ul', 'li', 'strong'];

  /**
   * Häufige Funktionswörter je Sprache (Sprachprüfung).
   */
  private const FUNCTION_WORDS = [
    'de' => [
      'der', 'die', 'das', 'und', 'ist', 'mit', 'für', 'nicht', 'den', 'wird', 'werden', 'bei',
      'auf', 'ein', 'eine', 'zur', 'zum', 'sind', 'vor', 'nach',
    ],
    'en' => [
      'the', 'and', 'is', 'with', 'for', 'not', 'are', 'of', 'to', 'be', 'on', 'by', 'this',
      'from', 'before', 'after', 'must', 'which', 'an', 'it',
    ],
    'fr' => [
      'le', 'la', 'les', 'et', 'est', 'avec', 'pour', 'des', 'du', 'une', 'un', 'dans', 'sur',
      'par', 'avant', 'après', 'doit', 'être', 'au', 'aux',
    ],
  ];

  /**
   * Formt die Modellantwort zu HTML aus p/ul/li/strong mit höchstens $maxWords.
   *
   * Markdown-Reste (Absätze, „- “-Listen, **fett**) werden umgesetzt; ist der
   * Text zu lang, wird er als ein Absatz an der Wortgrenze gekürzt („…“).
   */
  public static function toHtml(string $raw, int $maxWords): string {
    $raw = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/m', '', $raw));
    if ($raw === '') {
      return '';
    }
    if (preg_match('/<(p|ul|ol|li|br|div|strong|b|h\d)\b/i', $raw)) {
      $raw = (string) preg_replace('#<(/?)(b)>#i', '<$1strong>', $raw);
      $raw = (string) preg_replace('#<(/?)ol\b[^>]*>#i', '<$1ul>', $raw);
      $html = Xss::filter($raw, self::ALLOWED_TAGS);
    }
    else {
      $html = self::markdownToHtml($raw);
    }
    $html = trim((string) preg_replace('#<p>\s*</p>#', '', $html));
    if (self::wordCount($html) > $maxWords) {
      $words = preg_split('/\s+/u', self::plain($html), -1, PREG_SPLIT_NO_EMPTY) ?: [];
      $html = '<p>' . Html::escape(implode(' ', array_slice($words, 0, $maxWords))) . ' …</p>';
    }
    return $html;
  }

  /**
   * Klartext (Blöcke durch Leerzeichen getrennt, Entities aufgelöst).
   */
  public static function plain(string $html): string {
    $text = (string) preg_replace('#</(p|li|ul)>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Anzahl Wörter im Klartext.
   */
  public static function wordCount(string $html): int {
    return count(preg_split('/\s+/u', self::plain($html), -1, PREG_SPLIT_NO_EMPTY) ?: []);
  }

  /**
   * Wahrscheinlichste Sprache (de|en|fr) über Funktionswörter.
   *
   * NULL bei weniger als 12 Wörtern oder ohne Treffer.
   */
  public static function guessLanguage(string $html): ?string {
    $words = preg_split('/[^\p{L}]+/u', mb_strtolower(self::plain($html)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) < 12) {
      return NULL;
    }
    $counts = array_count_values($words);
    $scores = [];
    foreach (self::FUNCTION_WORDS as $langcode => $list) {
      $scores[$langcode] = array_sum(array_map(static fn(string $w): int => $counts[$w] ?? 0, $list));
    }
    arsort($scores);
    return (int) reset($scores) > 0 ? (string) key($scores) : NULL;
  }

  /**
   * Einfaches Markdown (Absätze, Listen, **fett**) zu erlaubtem HTML.
   */
  private static function markdownToHtml(string $text): string {
    $html = '';
    foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $block) {
      $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn(string $l): bool => $l !== ''));
      if ($lines === []) {
        continue;
      }
      $isList = array_filter($lines, static fn(string $l): bool => (bool) preg_match('/^([-*•]|\d+[.)])\s+/u', $l)) === $lines;
      if ($isList) {
        $items = array_map(static fn(string $l): string => '<li>' . self::inline((string) preg_replace('/^([-*•]|\d+[.)])\s+/u', '', $l)) . '</li>', $lines);
        $html .= '<ul>' . implode('', $items) . '</ul>';
      }
      else {
        $html .= '<p>' . self::inline(implode(' ', $lines)) . '</p>';
      }
    }
    return $html;
  }

  /**
   * Escaped Text mit **fett** als strong; Überschriften-Rauten entfernt.
   */
  private static function inline(string $text): string {
    $text = (string) preg_replace('/^#+\s*/u', '', $text);
    $escaped = Html::escape($text);
    return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
  }

}
