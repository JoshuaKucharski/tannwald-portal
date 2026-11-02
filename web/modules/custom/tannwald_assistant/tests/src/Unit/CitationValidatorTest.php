<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tannwald_assistant\AnswerTexts;
use Drupal\tannwald_assistant\CitationValidator;
use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zitatprüfung: gültig, ungültig, Mehrfach, Ablehnungssatz, ohne Zitat.
 */
#[Group('tannwald_assistant')]
final class CitationValidatorTest extends UnitTestCase {

  /**
   * Ergebnis mit $count Treffern.
   */
  private static function retrieval(int $count): RetrievalResult {
    $hits = [];
    for ($i = 1; $i <= $count; $i++) {
      $hits[] = ['chunk_id' => $i, 'nid' => 100 + $i, 'title' => 'Dok ' . $i];
    }
    return new RetrievalResult('Frage', 'de', 'hybrid', $hits);
  }

  /**
   * Gültige Zitate: beantwortet, Reihenfolge des ersten Auftretens.
   */
  public function testValidCitations(): void {
    $result = (new CitationValidator())->validate('Täglich trocken reinigen [2]. Wöchentlich nass [1]. Nur freigegebene Mittel [2].', self::retrieval(3));
    $this->assertSame(AnswerResult::STATUS_ANSWERED, $result->status);
    $this->assertTrue($result->isAnswered());
    $this->assertSame([2, 1], $result->citations);
    $this->assertSame([], $result->removed);
    $this->assertFalse($result->refusalDetected);
  }

  /**
   * Ungültige n werden entfernt; bleibt keine gültige, folgt Ablehnung.
   */
  public function testInvalidCitationsAreRemoved(): void {
    $validator = new CitationValidator();
    $mixed = $validator->validate('Erste Aussage [1]. Zweite Aussage [7]. Dritte [0].', self::retrieval(2));
    $this->assertSame(AnswerResult::STATUS_ANSWERED, $mixed->status);
    $this->assertSame([1], $mixed->citations);
    $this->assertSame([7, 0], $mixed->removed);
    $this->assertSame('Erste Aussage [1]. Zweite Aussage. Dritte.', $mixed->text);

    $onlyInvalid = $validator->validate('Erfundene Aussage [9].', self::retrieval(2));
    $this->assertSame(AnswerResult::STATUS_REFUSED, $onlyInvalid->status);
    $this->assertSame([], $onlyInvalid->citations);

    $noHits = $validator->validate('Aussage [1].', self::retrieval(0));
    $this->assertSame(AnswerResult::STATUS_REFUSED, $noHits->status, 'Ohne Treffer ist kein Zitat gültig.');
  }

  /**
   * Mehrfachzitate werden verdichtet, Schreibweisen vereinheitlicht.
   */
  public function testMultipleCitationsAreCollapsed(): void {
    $validator = new CitationValidator();
    $this->assertSame('Aussage [1].', $validator->validate('Aussage [1][1].', self::retrieval(2))->text);
    $this->assertSame('Aussage [2][1].', $validator->validate('Aussage [2] [2][1].', self::retrieval(2))->text);
    $this->assertSame('Aussage [1][2].', $validator->validate('Aussage [1][2][1].', self::retrieval(2))->text);
    $this->assertSame('Aussage [1][2].', $validator->validate('Aussage [1, 2].', self::retrieval(2))->text);
    $unified = $validator->validate('Aussage [Dokument 2] und [DOKUMENT 1].', self::retrieval(2));
    $this->assertSame('Aussage [2] und [1].', $unified->text);
    $this->assertSame([2, 1], $unified->citations);
  }

  /**
   * Der festgelegte Ablehnungssatz führt in jeder Sprache zur Ablehnung.
   */
  #[DataProvider('refusalProvider')]
  public function testRefusalSentenceIsDetected(string $answer): void {
    $result = (new CitationValidator())->validate($answer, self::retrieval(3));
    $this->assertSame(AnswerResult::STATUS_REFUSED, $result->status);
    $this->assertTrue($result->refusalDetected);
  }

  /**
   * Ablehnungssätze, auch mit Zitat, HTML und anderer Typografie.
   */
  public static function refusalProvider(): array {
    return [
      'de' => [AnswerTexts::REFUSAL['de']],
      'en' => [AnswerTexts::REFUSAL['en']],
      'fr mit typografischem Apostroph' => [str_replace("'", '’', AnswerTexts::REFUSAL['fr'])],
      'de mit Zitat' => ['Die freigegebenen Dokumente enthalten keine Antwort auf diese Frage [1].'],
      'de in HTML und klein' => ['<p>die freigegebenen dokumente enthalten keine antwort auf diese frage</p>'],
    ];
  }

  /**
   * Eine Antwort ohne jedes Zitat ist eine Ablehnung.
   */
  public function testAnswerWithoutCitationIsRefused(): void {
    $result = (new CitationValidator())->validate('Die Siegelstation wird täglich gereinigt.', self::retrieval(3));
    $this->assertSame(AnswerResult::STATUS_REFUSED, $result->status);
    $this->assertFalse($result->refusalDetected);
    $this->assertSame([], $result->citations);
  }

}
