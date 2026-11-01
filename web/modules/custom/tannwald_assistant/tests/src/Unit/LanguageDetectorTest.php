<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tannwald_assistant\LanguageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Spracherkennung: je Sprache fünf Sätze, kurze Eingaben → Rückfall.
 */
#[Group('tannwald_assistant')]
final class LanguageDetectorTest extends UnitTestCase {

  /**
   * Fünf Sätze je Sprache werden erkannt, unabhängig vom Rückfall.
   */
  #[DataProvider('sentenceProvider')]
  public function testSentences(string $expected, string $sentence): void {
    $detector = new LanguageDetector();
    foreach (['de', 'en', 'fr'] as $fallback) {
      $this->assertSame($expected, $detector->detect($sentence, $fallback), $sentence . ' (Rückfall ' . $fallback . ')');
    }
  }

  /**
   * Je Sprache fünf Sätze aus dem Portal-Alltag.
   */
  public static function sentenceProvider(): array {
    return [
      ['de', 'Wie oft muss die Siegelstation gereinigt werden?'],
      ['de', 'Wie ist die Lieferzeit für Teil 4711?'],
      ['de', 'Welche Reinigungsmittel sind für die TB-200 freigegeben?'],
      ['de', 'Was bedeutet der Fehlercode E-4711 an der Maschine?'],
      ['de', 'Wann muss ich die Folienrolle wechseln?'],
      ['en', 'How often must the sealing station be cleaned?'],
      ['en', 'What is E-4711?'],
      ['en', 'Which cleaning agents are approved for the TB-200?'],
      ['en', 'Where can I find the spare parts catalogue for my machine?'],
      ['en', 'Why does the film tear during sealing?'],
      ['fr', 'Pourquoi le film se déchire-t-il pendant le scellage ?'],
      ['fr', 'À quelle fréquence faut-il nettoyer la station de scellage ?'],
      ['fr', 'Quels produits de nettoyage sont autorisés pour la TB-200 ?'],
      ['fr', 'Où se trouve le catalogue des pièces de rechange ?'],
      ['fr', 'Comment remplacer la bobine de film sur la machine ?'],
    ];
  }

  /**
   * Kurze Eingaben und Texte ohne Stoppwort liefern die Nutzersprache.
   */
  public function testShortInputFallsBackToUserLanguage(): void {
    $detector = new LanguageDetector();
    $this->assertSame('fr', $detector->detect('E-4711', 'fr'));
    $this->assertSame('en', $detector->detect('Siegelstation reinigen', 'en'));
    $this->assertSame('de', $detector->detect('', 'de'));
    $this->assertSame('en', $detector->detect('TB-200 Folie Siegelnaht undicht', 'en'), 'Kein Stoppwort → Rückfall.');
  }

  /**
   * Ein Rückfall ausserhalb de/en/fr wird zu de.
   */
  public function testUnsupportedFallbackBecomesGerman(): void {
    $this->assertSame('de', (new LanguageDetector())->detect('E-4711', 'it'));
  }

  /**
   * Tokenisierung: Kleinbuchstaben, Bindestrich bleibt, Apostroph trennt.
   */
  public function testTokenize(): void {
    $this->assertSame(['what', 'is', 'e-4711'], LanguageDetector::tokenize('What is E-4711?'));
    $this->assertSame(['l', 'appareil', 'à', 'nettoyer'], LanguageDetector::tokenize("L'appareil à nettoyer"));
  }

  /**
   * Stoppwortlisten haben je mindestens 40 eindeutige Wörter.
   */
  public function testStopwordListSizes(): void {
    foreach (LanguageDetector::STOPWORDS as $langcode => $words) {
      $this->assertGreaterThanOrEqual(40, count(array_unique($words)), $langcode);
      $this->assertCount(count($words), array_unique($words), $langcode . ' ohne Doppelte');
    }
  }

}
