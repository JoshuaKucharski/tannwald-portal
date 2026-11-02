<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Usage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Schreibt Token-Verbrauch nach tannwald_usage (Service tannwald_ingest.usage).
 */
class UsageRecorder {

  /**
   * Erlaubte Operationen.
   */
  public const OPERATIONS = ['chat', 'embedding'];

  /**
   * Erlaubte Kontexte.
   */
  public const CONTEXTS = ['assistent', 'ingest', 'eval'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Hält einen Aufruf fest.
   *
   * @param string $modell
   *   Rohname des Modells (z. B. bge-m3, qwen2.5:7b).
   * @param string $operation
   *   Operation: chat|embedding.
   * @param int $promptTokens
   *   Eingabe-Tokens.
   * @param int $completionTokens
   *   Ausgabe-Tokens (Embeddings: 0).
   * @param string $kontext
   *   Kontext: assistent|ingest|eval.
   * @param float $kostenChf
   *   Kosten in CHF (Ollama: 0).
   */
  public function record(string $modell, string $operation, int $promptTokens, int $completionTokens, string $kontext, float $kostenChf = 0.0): void {
    if (!in_array($operation, self::OPERATIONS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown usage operation "%s".', $operation));
    }
    if (!in_array($kontext, self::CONTEXTS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown usage context "%s".', $kontext));
    }
    $this->database->insert('tannwald_usage')->fields([
      'modell' => mb_substr($modell, 0, 64),
      'operation' => $operation,
      'prompt_tokens' => max(0, $promptTokens),
      'completion_tokens' => max(0, $completionTokens),
      'kosten_chf' => round($kostenChf, 4),
      'kontext' => $kontext,
      'created' => $this->time->getRequestTime(),
    ])->execute();
  }

}
