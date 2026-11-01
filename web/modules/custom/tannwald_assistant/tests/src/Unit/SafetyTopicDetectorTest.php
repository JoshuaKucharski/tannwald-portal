<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_assistant\SafetyTopicDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Sicherheitserkennung mit der ausgelieferten Begriffsliste (config/install).
 */
#[Group('tannwald_assistant')]
final class SafetyTopicDetectorTest extends UnitTestCase {

  /**
   * Detektor mit den Standardwerten aus config/install.
   */
  private function detector(): SafetyTopicDetector {
    $settings = Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/tannwald_assistant.settings.yml');
    return new SafetyTopicDetector($this->getConfigFactoryStub(['tannwald_assistant.settings' => $settings]));
  }

  /**
   * Ergebnis mit den gegebenen heading_paths in Rangfolge.
   */
  private static function hits(array $headingPaths): RetrievalResult {
    $hits = [];
    foreach ($headingPaths as $i => $path) {
      $hits[] = ['chunk_id' => $i + 1, 'nid' => 10 + $i, 'heading_path' => $path];
    }
    return new RetrievalResult('Frage', 'de', 'hybrid', $hits);
  }

  /**
   * Begriffe in drei Sprachen (Wortgrenzen, Stern, Umlaute, Trenner).
   */
  #[DataProvider('safetyQuestionProvider')]
  public function testSafetyTermsInThreeLanguages(string $langcode, string $question): void {
    $detector = $this->detector();
    $this->assertNotNull($detector->matchedTerm($question, $langcode), $question);
    $this->assertTrue($detector->isSafety($question, self::hits(['7 Reinigung › 7.3 Reinigung Siegelstation']), $langcode));
  }

  /**
   * Sicherheitsfragen.
   */
  public static function safetyQuestionProvider(): array {
    return [
      'de Schutzhaube' => ['de', 'Wie kann ich die Schutzhaube während des Betriebs offen lassen?'],
      'de Plural über Stern' => ['de', 'Wo sind die Schutzhauben verriegelt?'],
      'de überbrücken' => ['de', 'Darf ich den Sicherheitsschalter überbrücken?'],
      'de ohne Umlaute' => ['de', 'Kann man den Schalter ueberbruecken?'],
      'de Not-Halt als Nothalt' => ['de', 'Der Nothalt reagiert verzögert.'],
      'de Reinigungsmittel' => ['de', 'Welches Reinigungsmittel ist erlaubt?'],
      'de Quetsch-' => ['de', 'Besteht Quetschgefahr an der Siegelbacke?'],
      'de stromlos' => ['de', 'Muss die Linie stromlos sein?'],
      'en lockout' => ['en', 'How do I perform lockout before cleaning?'],
      'en interlock' => ['en', 'Can I bypass the interlock?'],
      'en light curtain' => ['en', 'Is the light curtain required during setup?'],
      'en high voltage' => ['en', 'Where is the high voltage area?'],
      'fr consignation' => ['fr', 'Comment faire la consignation de la machine ?'],
      'fr interrupteur de sécurité' => ['fr', 'Puis-je ponter l’interrupteur de sécurité ?'],
      'fr arrêt d’urgence' => ['fr', 'Où est l’arrêt d’urgence ?'],
      'fr haute tension' => ['fr', 'La zone haute tension est-elle protégée ?'],
      'de Frage mit en-Begriff' => ['de', 'Wo ist der E-Stop an der TB-200?'],
    ];
  }

  /**
   * Ein Top-3-Treffer mit Sicherheitsabschnitt genügt; Rang 4 nicht.
   */
  public function testHeadingPathRule(): void {
    $detector = $this->detector();
    $question = 'Wie öffne ich die Klappe an der Siegelstation?';
    $this->assertNull($detector->matchedTerm($question, 'de'));
    $second = self::hits(['7 Reinigung', '2 Sicherheit › 2.3 Schutzeinrichtungen']);
    $this->assertTrue($detector->isSafety($question, $second, 'de'));
    $this->assertSame([2], $detector->safetyHitNumbers($second));
    $this->assertTrue($detector->isSafety($question, self::hits(['2 Safety › 2.1 Guards']), 'de'));
    $this->assertTrue($detector->isSafety($question, self::hits(['A', 'B', '2 Sécurité › 2.2 Capots']), 'de'));
    $this->assertFalse($detector->isSafety($question, self::hits(['A', 'B', 'C', '2 Sicherheitshinweise']), 'de'), 'Nur die drei besten Treffer zählen.');
    $this->assertTrue(SafetyTopicDetector::isSafetyHeading('2 Sicherheitshinweise'));
  }

  /**
   * Fachfragen ohne Sicherheitsbezug bleiben normal.
   */
  #[DataProvider('negativeProvider')]
  public function testNegativeCases(string $langcode, string $question): void {
    $detector = $this->detector();
    $this->assertNull($detector->matchedTerm($question, $langcode), $question);
    $this->assertFalse($detector->isSafety($question, self::hits(['9 Störungen › 9.4 Störungen an der Siegelstation']), $langcode));
  }

  /**
   * Negative Fälle.
   */
  public static function negativeProvider(): array {
    return [
      'Siegeltemperatur' => ['de', 'Welche Siegeltemperatur gilt für PET-Folie?'],
      'gereinigt ist nicht Reinigungsmittel' => ['de', 'Wie oft muss die Siegelstation gereinigt werden?'],
      'Lieferzeit' => ['de', 'Wie ist die Lieferzeit für Teil 4711?'],
      'Druckluft nur als Wort' => ['de', 'Welchen Druckluftanschluss hat die TB-200?'],
      'Fehlercode' => ['de', 'Was bedeutet E-4711?'],
      'en delivery' => ['en', 'What is the delivery time for part 4711?'],
      'fr scellage' => ['fr', 'Pourquoi le film se déchire-t-il pendant le scellage ?'],
    ];
  }

  /**
   * Normalisierung: Umlaute, Akzente, Apostrophe.
   */
  public function testNormalize(): void {
    $this->assertSame("ueberbruecken strasse securite arret d'urgence", SafetyTopicDetector::normalize('Überbrücken Straße Sécurité arrêt d’urgence'));
  }

}
