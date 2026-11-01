<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Core\Session\AccountInterface;

/**
 * Laufende Antwort: was die Log-Zeile ausser dem Ergebnis braucht.
 *
 * @internal
 */
final class AnswerContext {

  /**
   * Zahl der Kontext-Chunks mit Injection-Verdacht.
   */
  public int $injectionSuspicion = 0;

  public function __construct(
    public readonly int|float $start,
    public readonly string $frage,
    public readonly AccountInterface $account,
    public readonly string $session,
    public readonly ?int $maschineNid,
  ) {}

}
