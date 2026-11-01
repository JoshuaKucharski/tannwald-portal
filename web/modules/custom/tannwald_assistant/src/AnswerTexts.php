<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

/**
 * Feste Texte des Assistenten je Antwortsprache (de, en, fr).
 *
 * Die Antwortsprache ist die Sprache der Frage, nicht die der Oberfläche; die
 * Texte liegen darum als Konstanten vor und werden über den langcode gewählt.
 * Englisch ist der Quellstring; translations/de.po enthält dieselben Paare
 * (PromptBuilderTest prüft die Übereinstimmung), fr folgt in 6.5 als .po.
 * Der Ablehnungssatz ist Vertrag: PromptBuilder schreibt ihn wörtlich in den
 * Systemprompt, CitationValidator erkennt ihn, Theme und Evals lesen ihn.
 */
final class AnswerTexts {

  public const LANGCODES = ['de', 'en', 'fr'];

  public const DEFAULT_LANGCODE = 'de';

  /**
   * Genau ein Satz: Antwort steht nicht im Kontext (ohne Zitat).
   */
  public const REFUSAL = [
    'en' => 'The released documents do not contain an answer to this question.',
    'de' => 'Die freigegebenen Dokumente enthalten keine Antwort auf diese Frage.',
    'fr' => 'Les documents publiés ne contiennent pas de réponse à cette question.',
  ];

  /**
   * Hinweis auf den Support-Button (FA-AI-01, FA-AI-03).
   */
  public const SUPPORT_HINT = [
    'en' => 'Use the "Create support request" button to pass the question on to our service team.',
    'de' => 'Über den Button „Support-Anfrage erstellen“ geben Sie die Frage an unseren Service weiter.',
    'fr' => 'Le bouton « Créer une demande de support » transmet la question à notre service.',
  ];

  /**
   * Sicherheitsmodus (ohne LLM); @references = Verweise mit [n].
   */
  public const SAFETY = [
    'en' => 'Your question concerns a safety topic. The safety chapter of the operating instructions and these sections are authoritative: @references.',
    'de' => 'Ihre Frage betrifft ein Sicherheitsthema. Massgebend sind das Sicherheitskapitel der Betriebsanleitung und diese Stellen: @references.',
    'fr' => 'Votre question concerne un sujet de sécurité. Le chapitre sécurité du manuel d’utilisation et ces passages font foi : @references.',
  ];

  /**
   * Pflichtsatz des Sicherheitsmodus (Master-Präzisierung 4.1).
   */
  public const SAFETY_FULL_INSTRUCTIONS = [
    'en' => 'The complete instructions apply; no summary is given.',
    'de' => 'Die vollständige Anleitung gilt; eine Kurzfassung wird nicht gegeben.',
    'fr' => 'Les instructions complètes s’appliquent ; aucun résumé n’est donné.',
  ];

  /**
   * Frage länger als 1'000 Zeichen.
   */
  public const TOO_LONG = [
    'en' => 'Your question is too long. Please keep it to at most 1,000 characters.',
    'de' => 'Ihre Frage ist zu lang. Bitte fassen Sie sie in höchstens 1\'000 Zeichen.',
    'fr' => 'Votre question est trop longue. Veuillez la limiter à 1 000 caractères.',
  ];

  /**
   * Leere Frage.
   */
  public const EMPTY = [
    'en' => 'Please enter a question.',
    'de' => 'Bitte geben Sie eine Frage ein.',
    'fr' => 'Veuillez saisir une question.',
  ];

  /**
   * Assistent aus, Limit erreicht oder Provider weg (status limit).
   */
  public const UNAVAILABLE = [
    'en' => 'The assistant is currently unavailable. You can still use the search.',
    'de' => 'Der Assistent steht zurzeit nicht zur Verfügung. Die Suche können Sie weiterhin nutzen.',
    'fr' => 'L’assistant n’est pas disponible pour le moment. La recherche reste accessible.',
  ];

  /**
   * Text einer Konstante in der gewünschten Sprache (Rückfall de).
   *
   * @param array<string, string> $texts
   *   Eine der Konstanten dieser Klasse.
   * @param string $langcode
   *   Antwortsprache.
   */
  public static function get(array $texts, string $langcode): string {
    return $texts[$langcode] ?? $texts[self::DEFAULT_LANGCODE];
  }

  /**
   * Antwort-HTML einer Ablehnung: Ablehnungssatz plus Support-Hinweis.
   */
  public static function refusalHtml(string $langcode): string {
    return '<p>' . self::escape(self::get(self::REFUSAL, $langcode)) . '</p><p>' . self::escape(self::get(self::SUPPORT_HINT, $langcode)) . '</p>';
  }

  /**
   * Antwort-HTML aus einem Hinweistext (ein Absatz).
   */
  public static function hintHtml(array $texts, string $langcode): string {
    return '<p>' . self::escape(self::get($texts, $langcode)) . '</p>';
  }

  /**
   * Alle Konstanten mit Quellstring, für den .po-Abgleich.
   *
   * @return array<string, array<string, string>>
   *   Name der Konstante => Texte je Sprache.
   */
  public static function all(): array {
    return [
      'REFUSAL' => self::REFUSAL,
      'SUPPORT_HINT' => self::SUPPORT_HINT,
      'SAFETY' => self::SAFETY,
      'SAFETY_FULL_INSTRUCTIONS' => self::SAFETY_FULL_INSTRUCTIONS,
      'TOO_LONG' => self::TOO_LONG,
      'EMPTY' => self::EMPTY,
      'UNAVAILABLE' => self::UNAVAILABLE,
    ];
  }

  /**
   * HTML-Escape für feste Texte.
   */
  private static function escape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
