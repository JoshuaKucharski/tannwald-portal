<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Result;

/**
 * Ergebnis des CitationValidator (unveränderlich).
 */
final class CitationResult {

  /**
   * Erzeugt das Ergebnis.
   *
   * @param string $status
   *   beantwortet (mindestens ein gültiges [n], kein Ablehnungssatz) oder
   *   abgelehnt.
   * @param string $text
   *   Antworttext ohne ungültige und ohne doppelte Zitate.
   * @param int[] $citations
   *   Gültige n in der Reihenfolge des ersten Auftretens.
   * @param int[] $removed
   *   Entfernte ungültige n (ausserhalb 1…Trefferzahl).
   * @param bool $refusalDetected
   *   TRUE, wenn der festgelegte Ablehnungssatz erkannt wurde.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $text,
    public readonly array $citations,
    public readonly array $removed,
    public readonly bool $refusalDetected,
  ) {}

  /**
   * TRUE, wenn die Antwort als belegt gilt.
   */
  public function isAnswered(): bool {
    return $this->status === AnswerResult::STATUS_ANSWERED;
  }

}
