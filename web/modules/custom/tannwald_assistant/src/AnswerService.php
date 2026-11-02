<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\tannwald_assistant\Limit\LimitCheckInterface;
use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Psr\Log\LoggerInterface;

/**
 * Orchestriert eine Antwort (Pflichtenheft §3.2, §6.3, §8).
 *
 * Answer(): Länge → assistant_enabled → Limit → retrieve() → ab hier wie
 * answerWithContext(): Injection-Heuristik (nur Protokoll) → keine Treffer →
 * abgelehnt; Sicherheitsthema → Vorlage ohne LLM; sonst Prompt → Chat →
 * CitationValidator → Ausgabefilter → zitierte Quellen. Jede Antwort schreibt
 * eine Zeile in tannwald_assistant_log. Usage schreibt seit 4.2 nur der
 * UsageTracker (Event des AI-Moduls, Tag tannwald_assistant).
 *
 * ai.provider ist als FactoryInterface getippt (AiProviderPluginManager ist
 * final); createInstance() liefert den ProviderProxy des AI-Moduls.
 */
final class AnswerService {

  public const MAX_LENGTH = 1000;

  public const LOG_TABLE = 'tannwald_assistant_log';

  /**
   * Tag am Chat-Aufruf; der UsageTracker zählt nur Aufrufe mit diesem Tag.
   */
  public const CHAT_TAG = 'tannwald_assistant';

  public function __construct(
    private readonly Retriever $retriever,
    private readonly SafetyTopicDetector $safetyDetector,
    private readonly PromptBuilder $promptBuilder,
    private readonly CitationValidator $citationValidator,
    private readonly OutputFilter $outputFilter,
    private readonly LanguageDetector $languageDetector,
    private readonly InjectionHeuristic $injectionHeuristic,
    private readonly FactoryInterface $aiProvider,
    private readonly LimitCheckInterface $limitCheck,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Beantwortet eine Frage für ein Konto.
   *
   * @param string $frage
   *   Frage (max. 1'000 Zeichen).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Fragendes Konto.
   * @param int|null $maschineNid
   *   Maschine, auf deren Typ/Seriennummer das Retrieval eingeschränkt wird.
   * @param string $session
   *   Gesprächs-ID des Clients (nur gehasht gespeichert).
   */
  public function answer(string $frage, AccountInterface $account, ?int $maschineNid, string $session): AnswerResult {
    $start = hrtime(TRUE);
    $frage = trim($frage);
    $context = new AnswerContext($start, $frage, $account, $session, $maschineNid);
    $langcode = $this->languageDetector->detect($frage, $account->getPreferredLangcode());
    if ($frage === '') {
      return $this->finish($context, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::hintHtml(AnswerTexts::EMPTY, $langcode));
    }
    if (mb_strlen($frage) > self::MAX_LENGTH) {
      return $this->finish($context, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::hintHtml(AnswerTexts::TOO_LONG, $langcode));
    }
    if (!$this->configFactory->get('tannwald_assistant.settings')->get('assistant_enabled')) {
      return $this->finish($context, $langcode, AnswerResult::STATUS_LIMIT, AnswerTexts::hintHtml(AnswerTexts::UNAVAILABLE, $langcode));
    }
    if (!$this->limitCheck->allowsAnswer($account)) {
      return $this->finish($context, $langcode, AnswerResult::STATUS_LIMIT, AnswerTexts::hintHtml(AnswerTexts::LIMIT_REACHED, $langcode));
    }
    $result = $this->retriever->retrieve($frage, $account, $maschineNid);
    return $this->respond($context, $result, $result->langcode);
  }

  /**
   * Antwortet auf einem fertigen RetrievalResult (Evals 4.3, Hand-Läufe).
   *
   * Derselbe Weg wie answer() ab der Injection-Heuristik; ohne Längen-,
   * Schalter- und Limit-Prüfung.
   */
  public function answerWithContext(string $frage, RetrievalResult $result, AccountInterface $account, string $session, ?string $langcode = NULL): AnswerResult {
    $context = new AnswerContext(hrtime(TRUE), trim($frage), $account, $session, NULL);
    return $this->respond($context, $result, $langcode ?? $result->langcode);
  }

  /**
   * Normalisierte Frage für die Gruppierung (Unbeantwortete Fragen).
   *
   * Kleinbuchstaben, Whitespace verdichtet, Satzzeichen am Ende entfernt,
   * höchstens 255 Zeichen.
   */
  public static function normalizeQuestion(string $frage): string {
    $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $frage) ?? ''), 'UTF-8');
    $text = preg_replace('/[\s?!.;:]+$/u', '', $text) ?? $text;
    return mb_substr($text, 0, 255);
  }

  /**
   * Gemeinsamer Weg ab dem Retrieval-Ergebnis.
   */
  private function respond(AnswerContext $context, RetrievalResult $result, string $langcode): AnswerResult {
    foreach ($this->injectionHeuristic->scan($result) as $finding) {
      $context->injectionSuspicion++;
      $this->logger->notice('injection_verdacht nid @nid chunk @chunk pattern @pattern', [
        '@nid' => $finding['nid'],
        '@chunk' => $finding['chunk_id'],
        '@pattern' => $finding['pattern'],
      ]);
    }

    if ($result->isEmpty()) {
      return $this->finish($context, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::refusalHtml($langcode));
    }

    if ($this->safetyDetector->isSafety($context->frage, $result, $langcode)) {
      $template = $this->promptBuilder->safetyAnswer($result, $langcode);
      return $this->finish($context, $langcode, AnswerResult::STATUS_SAFETY, $template['html'], self::sources($result, $template['citations']), [], TRUE);
    }

    $settings = $this->configFactory->get('tannwald_assistant.settings');
    $input = $this->promptBuilder->build($context->frage, $result, $langcode, FALSE);
    try {
      $provider = $this->aiProvider->createInstance((string) $settings->get('provider'));
      $provider->setConfiguration([
        'temperature' => PromptBuilder::TEMPERATURE,
        'max_tokens' => PromptBuilder::MAX_TOKENS,
      ]);
      $output = $provider->chat($input, (string) $settings->get('chat_model'), [self::CHAT_TAG]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Chat provider failed: @message', ['@message' => $e->getMessage()]);
      return $this->finish($context, $langcode, AnswerResult::STATUS_LIMIT, AnswerTexts::hintHtml(AnswerTexts::UNAVAILABLE, $langcode));
    }

    $tokens = self::tokens($output);
    $normalized = $output->getNormalized();
    $text = $normalized instanceof ChatMessage ? $normalized->getText() : '';
    $check = $this->citationValidator->validate($text, $result);
    $html = $check->isAnswered() ? $this->outputFilter->filter($check->text) : '';
    $substance = trim(preg_replace('/\[\d+\]/', '', strip_tags($html)) ?? '');
    if (!$check->isAnswered() || $substance === '') {
      return $this->finish($context, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::refusalHtml($langcode), [], $tokens);
    }
    return $this->finish($context, $langcode, AnswerResult::STATUS_ANSWERED, $html, self::sources($result, $check->citations), $tokens);
  }

  /**
   * Schreibt die Log-Zeile und baut das Ergebnis.
   */
  private function finish(AnswerContext $context, string $langcode, string $status, string $html, array $quellen = [], array $tokens = [], bool $safety = FALSE): AnswerResult {
    $dauerMs = (int) round((hrtime(TRUE) - $context->start) / 1e6);
    $salt = Settings::getHashSalt();
    $logId = (int) $this->database->insert(self::LOG_TABLE)
      ->fields([
        'session_hash' => self::hash($context->session, $salt),
        'uid_hash' => self::hash((string) $context->account->id(), $salt),
        'langcode' => $langcode,
        'frage' => mb_substr($context->frage, 0, 2 * self::MAX_LENGTH),
        'frage_normalisiert' => self::normalizeQuestion($context->frage),
        'antwort' => $html,
        'quellen' => json_encode(array_values($quellen), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'status' => $status,
        'dauer_ms' => $dauerMs,
        'injection_verdacht' => $context->injectionSuspicion,
        'maschine_nid' => $context->maschineNid,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    return new AnswerResult($status, $html, $quellen, $logId, $dauerMs, $tokens, $langcode, $safety);
  }

  /**
   * Hash-Regel des Logs: sha256(Wert . hash_salt), 64 Hex-Zeichen.
   */
  public static function hash(string $value, ?string $salt = NULL): string {
    return hash('sha256', $value . ($salt ?? Settings::getHashSalt()));
  }

  /**
   * Quellen der zitierten n in Zitierreihenfolge; n bleibt die Prompt-Nummer.
   *
   * @param \Drupal\tannwald_assistant\Result\RetrievalResult $result
   *   Treffer, die im Prompt bzw. in der Vorlage standen.
   * @param int[] $citations
   *   Zitierte n.
   */
  private static function sources(RetrievalResult $result, array $citations): array {
    $sources = [];
    foreach ($citations as $n) {
      $hit = $result->hit($n);
      if ($hit === NULL) {
        continue;
      }
      $sources[] = [
        'n' => $n,
        'nid' => (int) $hit['nid'],
        'titel' => (string) $hit['title'],
        'version' => (string) $hit['version'],
        'heading_path' => (string) $hit['heading_path'],
        'url' => (string) $hit['url'],
        'langcode' => (string) $hit['langcode'],
      ];
    }
    return $sources;
  }

  /**
   * Tokens aus der Provider-Antwort (OpenAI-kompatibel, Rückfall Ollama).
   *
   * @return array{prompt: int, completion: int}
   *   Tokens.
   */
  public static function tokens(ChatOutput $output): array {
    $usage = $output->getTokenUsage();
    $raw = $output->getRawOutput();
    $raw = is_array($raw) ? $raw : [];
    return [
      'prompt' => (int) ($usage->input ?? $raw['usage']['prompt_tokens'] ?? $raw['prompt_eval_count'] ?? 0),
      'completion' => (int) ($usage->output ?? $raw['usage']['completion_tokens'] ?? $raw['eval_count'] ?? 0),
    ];
  }

}
