<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\tannwald_assistant\Result\RetrievalResult;

/**
 * Erkennt eingebettete Anweisungen in Kontext-Chunks (NFA-SEC-04, TC-16).
 *
 * Nur Erkennung und Protokoll: die Antwort bleibt unverändert, der Schutz
 * liegt in Prompt (Blöcke sind Daten), CitationValidator und Ausgabefilter.
 * Vergleich auf SafetyTopicDetector::normalize() (Kleinbuchstaben, Umlaute
 * umschrieben, Akzente weg), Muster an Wortgrenzen.
 */
final class InjectionHeuristic {

  /**
   * Muster je Schlüssel (Sprache.Name => Regex ohne Begrenzer).
   */
  public const PATTERNS = [
    'de.ignore_rules' => 'ignorier\w*\s+(?:(?:du|sie)\s+)?(?:alle\s+)?(?:(?:bisherigen|vorherigen|obigen|vorangehenden|deine|ihre|die)\s+)?(?:regeln|anweisungen|vorgaben|instruktionen)',
    'de.forget_rules' => 'vergiss\w*\s+(?:alle\s+)?(?:(?:deine|die|bisherigen)\s+)?(?:regeln|anweisungen|vorgaben)',
    'de.answer_only' => 'antworte\w*\s+(?:(?:auf|bei)\s+jede\w*\s+frage\s+)?(?:nur|ausschliesslich|lediglich|immer)\s+mit',
    'de.addressed' => '(?:hinweis|anweisung|nachricht|befehl)\s+an\s+(?:den|die)\s+(?:assistenten|assistentin|chatbot|bot|ki)',
    'de.you_are_now' => 'du\s+bist\s+(?:jetzt|nun|ab\s+sofort)',
    'en.ignore_rules' => '(?:ignore|disregard|forget)\s+(?:all\s+)?(?:(?:previous|prior|above|earlier|the|your)\s+)?(?:instructions|rules|prompts?)',
    'en.answer_only' => '(?:answer|respond|reply)\s+(?:to\s+every\s+question\s+)?(?:only|exclusively|always)\s+with',
    'en.you_are_now' => 'you\s+are\s+now',
    'en.system_prompt' => '(?:new|reveal\s+(?:the|your))\s+system\s+prompt',
    'fr.ignore_rules' => 'ignore[rz]?\s+(?:toutes\s+)?(?:les\s+|vos\s+|tes\s+)?(?:instructions|regles|consignes)',
    'fr.disregard' => 'ne\s+tien[ts]?\s+pas\s+compte\s+des\s+(?:instructions|regles|consignes)',
    'fr.answer_only' => 'repond(?:s|ez)?\s+(?:a\s+chaque\s+question\s+)?(?:uniquement|seulement|toujours)\s+(?:par|avec)',
    'fr.you_are_now' => '(?:tu\s+es|vous\s+etes)\s+(?:maintenant|desormais)',
  ];

  /**
   * Prüft alle Treffer eines Ergebnisses.
   *
   * @return array<int, array{n: int, nid: int, chunk_id: int, pattern: string}>
   *   Je verdächtigem Chunk ein Eintrag (erstes passendes Muster).
   */
  public function scan(RetrievalResult $result): array {
    $findings = [];
    foreach ($result->hits as $hit) {
      $pattern = $this->match((string) $hit['text']);
      if ($pattern !== NULL) {
        $findings[] = [
          'n' => (int) $hit['n'],
          'nid' => (int) $hit['nid'],
          'chunk_id' => (int) $hit['chunk_id'],
          'pattern' => $pattern,
        ];
      }
    }
    return $findings;
  }

  /**
   * Schlüssel des ersten passenden Musters oder NULL.
   */
  public function match(string $text): ?string {
    $normalized = preg_replace('/\s+/u', ' ', SafetyTopicDetector::normalize($text)) ?? '';
    foreach (self::PATTERNS as $key => $regex) {
      if (preg_match('/(?<![\p{L}\p{N}])' . $regex . '(?![\p{L}\p{N}])/u', $normalized) === 1) {
        return $key;
      }
    }
    return NULL;
  }

}
