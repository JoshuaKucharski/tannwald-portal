<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Result;

/**
 * Antwort des Assistenten (unveränderlich).
 *
 * ToArray() ist genau die Antwort von POST /api/assistant (RunBook §1.1):
 * {status, antwort_html, quellen: [{n, nid, titel, version, heading_path,
 * url, langcode}], log_id} — langcode seit 4.2 (Vertragsänderung 20).
 * toFullArray() ergänzt dauer_ms, tokens, langcode und modus_safety für
 * Evals und Hand-Läufe.
 */
final class AnswerResult {

  public const STATUS_ANSWERED = 'beantwortet';
  public const STATUS_REFUSED = 'abgelehnt';
  public const STATUS_SAFETY = 'safety';
  public const STATUS_LIMIT = 'limit';

  public const STATUSES = [
    self::STATUS_ANSWERED,
    self::STATUS_REFUSED,
    self::STATUS_SAFETY,
    self::STATUS_LIMIT,
  ];

  /**
   * Schlüssel einer Quelle in der Reihenfolge des HTTP-Vertrags.
   */
  public const SOURCE_KEYS = ['n', 'nid', 'titel', 'version', 'heading_path', 'url', 'langcode'];

  /**
   * Zitierte Quellen in Zitierreihenfolge; n = Nummer aus dem Prompt.
   *
   * @var array<int, array<string, mixed>>
   */
  public readonly array $quellen;

  /**
   * Tokens der Provider-Antwort {prompt, completion}.
   *
   * @var array{prompt: int, completion: int}
   */
  public readonly array $tokens;

  /**
   * Erzeugt die Antwort.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $antwortHtml,
    array $quellen = [],
    public readonly int $logId = 0,
    public readonly int $dauerMs = 0,
    array $tokens = [],
    public readonly string $langcode = 'de',
    public readonly bool $modusSafety = FALSE,
  ) {
    if (!in_array($status, self::STATUSES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown answer status "%s".', $status));
    }
    $sources = [];
    foreach (array_values($quellen) as $source) {
      $row = [];
      foreach (self::SOURCE_KEYS as $key) {
        $row[$key] = $source[$key] ?? (in_array($key, ['n', 'nid'], TRUE) ? 0 : '');
      }
      $row['n'] = (int) $row['n'];
      $row['nid'] = (int) $row['nid'];
      $sources[] = $row;
    }
    $this->quellen = $sources;
    $this->tokens = [
      'prompt' => (int) ($tokens['prompt'] ?? 0),
      'completion' => (int) ($tokens['completion'] ?? 0),
    ];
  }

  /**
   * Antwort von POST /api/assistant, genau nach Vertrag.
   */
  public function toArray(): array {
    return [
      'status' => $this->status,
      'antwort_html' => $this->antwortHtml,
      'quellen' => $this->quellen,
      'log_id' => $this->logId,
    ];
  }

  /**
   * Vertrag plus Messwerte (Evals 4.3, Hand-Läufe).
   */
  public function toFullArray(): array {
    return $this->toArray() + [
      'dauer_ms' => $this->dauerMs,
      'tokens' => $this->tokens,
      'langcode' => $this->langcode,
      'modus_safety' => $this->modusSafety,
    ];
  }

}
