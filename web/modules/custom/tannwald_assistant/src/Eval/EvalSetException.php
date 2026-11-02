<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

/**
 * Eval-Set oder Quelle nicht verwendbar (Abbruch vor dem Lauf).
 */
final class EvalSetException extends \RuntimeException {

  /**
   * Fehler je Frage-ID.
   *
   * @param array<string, string> $errors
   *   Frage-ID => Grund.
   */
  public function __construct(
    public readonly array $errors,
  ) {
    $lines = [];
    foreach ($errors as $id => $reason) {
      $lines[] = $id . ': ' . $reason;
    }
    parent::__construct("Eval set not usable:\n" . implode("\n", $lines));
  }

}
