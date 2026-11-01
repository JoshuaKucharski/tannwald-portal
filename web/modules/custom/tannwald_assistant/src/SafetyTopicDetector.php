<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\tannwald_assistant\Result\RetrievalResult;

/**
 * Erkennt Sicherheitsthemen (FA-AI-04, TC-09).
 *
 * Sicherheit, wenn (a) ein Begriff aus safety_terms der Antwortsprache oder
 * der beiden anderen Sprachen in der Frage vorkommt — ein Begriff „A + B“
 * verlangt alle Teile (Bauteil + riskante Handlung, 6.1) —, oder (b) einer der
 * drei besten Treffer im heading_path „Sicherheit“, „Safety“ oder „Sécurité“
 * enthält **und** die Frage ein Risiko-Signalwort trägt (RISK_MARKERS, 6.1:
 * sonst lösten reine Informationsfragen über Sicherheitskapitel aus).
 * Vergleich auf normalisiertem Text: Kleinbuchstaben, ä/ö/ü/ß → ae/oe/ue/ss,
 * Akzente entfernt, Apostrophe vereinheitlicht. Begriffe gelten an
 * Wortgrenzen; ein Stern am Ende erlaubt Verlängerungen (Schutzhaube* →
 * Schutzhauben, Schutzhaubenschalter); Leerzeichen und Bindestriche in einem
 * Begriff passen auf Leerzeichen, Bindestrich oder nichts (Not-Halt →
 * Nothalt, Not Halt).
 */
final class SafetyTopicDetector {

  /**
   * Wörter im heading_path, die einen Sicherheitsabschnitt kennzeichnen.
   */
  public const HEADING_MARKERS = ['sicherheit', 'safety', 'securite'];

  /**
   * Anzahl der besten Treffer, deren heading_path geprüft wird.
   */
  public const HEADING_TOP = 3;

  /**
   * Risiko-Signalwörter für die Kapitel-Regel (gleiche Syntax wie Begriffe).
   */
  public const RISK_MARKERS = [
    'de' => [
      'ohne',
      'während',
      'trotzdem',
      'selbst',
      'allein*',
      'offen*',
      'öffn*',
      'entfern*',
      'berühr*',
      'greif*',
      'umgeh*',
      'überbrück*',
      'abschalt*',
      'schalte',
      'deaktivier*',
      'nachfüll*',
      'heiss*',
      'laufend*',
      'läuft',
    ],
    'en' => [
      'without',
      'while',
      'remove*',
      'disabl*',
      'touch*',
      'reach*',
      'open*',
      'bypass*',
      'bridg*',
      'running',
      'powered',
      'alone',
      'myself',
    ],
    'fr' => [
      'sans',
      'pendant',
      'retirer',
      'toucher',
      'moi-même',
      'contourn*',
      'ouvert*',
      'tourne',
      'seul*',
    ],
  ];

  /**
   * Zwischenspeicher der Muster je Sprache.
   *
   * @var array<string, array<string, string[]>>
   */
  private array $patterns = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * TRUE, wenn Frage oder Top-Treffer ein Sicherheitsthema anzeigen.
   */
  public function isSafety(string $frage, RetrievalResult $result, string $langcode): bool {
    return $this->matchedTerm($frage, $langcode) !== NULL
      || ($this->safetyHitNumbers($result) !== [] && $this->hasRiskMarker($frage));
  }

  /**
   * TRUE, wenn die Frage ein Risiko-Signalwort (alle Sprachen) enthält.
   */
  public function hasRiskMarker(string $frage): bool {
    $text = self::normalize($frage);
    foreach (self::RISK_MARKERS as $markers) {
      foreach ($markers as $marker) {
        if (self::matchesTerm($marker, $text)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Erster passender Begriff in der Frage oder NULL.
   */
  public function matchedTerm(string $frage, string $langcode): ?string {
    $text = self::normalize($frage);
    foreach ($this->orderedLangcodes($langcode) as $code) {
      foreach ($this->patternsFor($code) as $term => $patterns) {
        if (self::allMatch($patterns, $text)) {
          return $term;
        }
      }
    }
    return NULL;
  }

  /**
   * Nummern n der Top-3-Treffer mit Sicherheitsabschnitt im heading_path.
   *
   * @return int[]
   *   Trefferzahlen n in Rangfolge.
   */
  public function safetyHitNumbers(RetrievalResult $result, int $top = self::HEADING_TOP): array {
    $numbers = [];
    foreach (array_slice($result->hits, 0, $top) as $hit) {
      if (self::isSafetyHeading((string) $hit['heading_path'])) {
        $numbers[] = (int) $hit['n'];
      }
    }
    return $numbers;
  }

  /**
   * TRUE, wenn ein heading_path einen Sicherheitsabschnitt bezeichnet.
   */
  public static function isSafetyHeading(string $headingPath): bool {
    $normalized = self::normalize($headingPath);
    foreach (self::HEADING_MARKERS as $marker) {
      if (str_contains($normalized, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Normalisiert Text für den Vergleich.
   */
  public static function normalize(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
      'ä' => 'ae',
      'ö' => 'oe',
      'ü' => 'ue',
      'ß' => 'ss',
      '’' => "'",
      '‘' => "'",
      '`' => "'",
      'œ' => 'oe',
      'æ' => 'ae',
      ' ' => ' ',
    ]);
    if (class_exists(\Normalizer::class)) {
      $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
      if (is_string($decomposed)) {
        $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
      }
    }
    else {
      $text = strtr($text, [
        'à' => 'a',
        'â' => 'a',
        'ç' => 'c',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'î' => 'i',
        'ï' => 'i',
        'ô' => 'o',
        'û' => 'u',
        'ù' => 'u',
        'ÿ' => 'y',
      ]);
    }
    return $text;
  }

  /**
   * TRUE, wenn ein Begriff (auch „A + B“) im normalisierten Text vorkommt.
   */
  public static function matchesTerm(string $term, string $normalizedText): bool {
    return self::allMatch(self::patternsForTerm($term), $normalizedText);
  }

  /**
   * Muster eines Begriffs; „A + B“ ergibt ein Muster je Teil.
   *
   * @return string[]
   *   Regexe (alle müssen passen).
   */
  public static function patternsForTerm(string $term): array {
    $parts = array_values(array_filter(array_map('trim', explode(' + ', $term)), static fn (string $part): bool => trim($part, '* ') !== ''));
    return array_map([self::class, 'patternForTerm'], $parts);
  }

  /**
   * TRUE, wenn alle Muster passen (leere Liste: FALSE).
   *
   * @param string[] $patterns
   *   Regexe.
   * @param string $text
   *   Normalisierter Text.
   */
  private static function allMatch(array $patterns, string $text): bool {
    if ($patterns === []) {
      return FALSE;
    }
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text) !== 1) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Baut das Suchmuster eines Begriffs.
   */
  public static function patternForTerm(string $term): string {
    $term = trim(self::normalize($term));
    $prefix = str_ends_with($term, '*');
    $term = rtrim($term, '*');
    $parts = preg_split('/[\s\-]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $quoted = array_map(static fn (string $part): string => preg_quote($part, '/'), $parts);
    $body = implode('[\s\-]?', $quoted);
    return '/(?<![\p{L}\p{N}])' . $body . ($prefix ? '' : '(?![\p{L}\p{N}])') . '/u';
  }

  /**
   * Antwortsprache zuerst, dann die übrigen Sprachen.
   *
   * @return string[]
   *   Sprachcodes.
   */
  private function orderedLangcodes(string $langcode): array {
    $codes = AnswerTexts::LANGCODES;
    if (in_array($langcode, $codes, TRUE)) {
      $codes = array_merge([$langcode], array_diff($codes, [$langcode]));
    }
    return $codes;
  }

  /**
   * Muster aller Begriffe einer Sprache aus der Konfiguration.
   *
   * @return array<string, string[]>
   *   Begriff => Regexe (alle müssen passen).
   */
  private function patternsFor(string $langcode): array {
    if (!isset($this->patterns[$langcode])) {
      $terms = (array) ($this->configFactory->get('tannwald_assistant.settings')->get('safety_terms.' . $langcode) ?? []);
      $this->patterns[$langcode] = [];
      foreach ($terms as $term) {
        if (is_string($term) && trim($term, " *\t") !== '') {
          $this->patterns[$langcode][$term] = self::patternsForTerm($term);
        }
      }
    }
    return $this->patterns[$langcode];
  }

}
