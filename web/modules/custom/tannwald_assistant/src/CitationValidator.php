<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\CitationResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;

/**
 * Prüft die Zitate einer Modellantwort (Pflichtenheft §8, FA-AI-01).
 *
 * Regeln: (1) Schreibweisen [1, 2], [1,2] und [Dokument 1] werden zu [1][2]
 * bzw. [1] vereinheitlicht. (2) [n] ausserhalb 1…Trefferzahl wird entfernt.
 * (3) Wiederholungen in einer Zitatgruppe ([1][1], [1] [1], [1][2][1])
 * werden verdichtet. (4) Gültige n in Reihenfolge des ersten Auftretens.
 * (5) Ohne gültiges Zitat → abgelehnt, auch wenn das Modell geantwortet hat.
 * (6) Enthält die Antwort den festgelegten Ablehnungssatz (einer der drei
 * Sprachen) → abgelehnt.
 */
final class CitationValidator {

  /**
   * Prüft eine Antwort gegen die Treffer, die im Prompt standen.
   */
  public function validate(string $antwort, RetrievalResult $result): CitationResult {
    $max = $result->count();
    $text = self::unifyNotation($antwort);

    $removed = [];
    $text = preg_replace_callback('/\s*\[(\d{1,3})\]/u', static function (array $m) use ($max, &$removed): string {
      $n = (int) $m[1];
      if ($n < 1 || $n > $max) {
        $removed[] = $n;
        return '';
      }
      return $m[0];
    }, $text) ?? $text;

    $text = self::collapseGroups($text);

    $citations = [];
    if (preg_match_all('/\[(\d{1,3})\]/u', $text, $matches)) {
      foreach ($matches[1] as $value) {
        $n = (int) $value;
        if (!in_array($n, $citations, TRUE)) {
          $citations[] = $n;
        }
      }
    }

    $refusal = self::containsRefusal($text);
    $status = ($citations !== [] && !$refusal) ? AnswerResult::STATUS_ANSWERED : AnswerResult::STATUS_REFUSED;
    return new CitationResult($status, trim($text), $citations, array_values(array_unique($removed)), $refusal);
  }

  /**
   * TRUE, wenn der Text einen der festgelegten Ablehnungssätze enthält.
   */
  public static function containsRefusal(string $text): bool {
    $haystack = self::normalizeSentence($text);
    foreach (AnswerTexts::REFUSAL as $sentence) {
      if (str_contains($haystack, self::normalizeSentence($sentence))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Vereinheitlicht Zitatschreibweisen zu [n].
   */
  private static function unifyNotation(string $text): string {
    // [Dokument 1], [DOKUMENT 1], [Document 1], [Doc. 1] → [1].
    $text = preg_replace('/\[\s*(?:dokument|document|doc\.?)\s*(\d{1,3})\s*\]/iu', '[$1]', $text) ?? $text;
    // [1, 2] / [1,2] / [1; 2] → [1][2].
    return preg_replace_callback('/\[\s*(\d{1,3}(?:\s*[,;]\s*\d{1,3})+)\s*\]/u', static function (array $m): string {
      $numbers = preg_split('/\s*[,;]\s*/u', trim($m[1])) ?: [];
      return implode('', array_map(static fn (string $n): string => '[' . (int) $n . ']', $numbers));
    }, $text) ?? $text;
  }

  /**
   * Verdichtet Wiederholungen innerhalb einer Gruppe direkt folgender Zitate.
   */
  private static function collapseGroups(string $text): string {
    return preg_replace_callback('/\[\d{1,3}\](?:\s*\[\d{1,3}\])+/u', static function (array $m): string {
      preg_match_all('/\[(\d{1,3})\]/u', $m[0], $numbers);
      $unique = array_values(array_unique(array_map('intval', $numbers[1])));
      return implode('', array_map(static fn (int $n): string => '[' . $n . ']', $unique));
    }, $text) ?? $text;
  }

  /**
   * Normalisiert einen Satz für den Vergleich (Tags, Gross/Klein, Zeichen).
   */
  private static function normalizeSentence(string $text): string {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, ['’' => "'", '‘' => "'", ' ' => ' ']);
    $text = preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $text) ?? $text;
    return ' ' . trim($text) . ' ';
  }

}
