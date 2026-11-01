<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\tannwald_assistant\Limit\LimitCheckInterface;
use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_ingest\Usage\UsageRecorder;
use Psr\Log\LoggerInterface;

/**
 * Orchestriert eine Antwort (Pflichtenheft §3.2, §6.3, §8).
 *
 * Answer(): Länge → assistant_enabled → Limit → retrieve() → ab hier wie
 * answerWithContext(): keine Treffer → abgelehnt; Sicherheitsthema → Vorlage
 * ohne LLM; sonst Prompt → Chat → CitationValidator → Ausgabefilter →
 * zitierte Quellen. Jede Antwort schreibt eine Zeile in
 * tannwald_assistant_log; ein Chat-Aufruf schreibt Usage (kontext assistent).
 *
 * ai.provider ist als PluginManagerInterface getippt (AiProviderPluginManager
 * ist final); createInstance() liefert den ProviderProxy des AI-Moduls.
 */
final class AnswerService {

  public const MAX_LENGTH = 1000;

  public const LOG_TABLE = 'tannwald_assistant_log';

  public function __construct(
    private readonly Retriever $retriever,
    private readonly SafetyTopicDetector $safetyDetector,
    private readonly PromptBuilder $promptBuilder,
    private readonly CitationValidator $citationValidator,
    private readonly OutputFilter $outputFilter,
    private readonly LanguageDetector $languageDetector,
    private readonly PluginManagerInterface $aiProvider,
    private readonly UsageRecorder $usageRecorder,
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
    $langcode = $this->languageDetector->detect($frage, $account->getPreferredLangcode());
    if ($frage === '') {
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::hintHtml(AnswerTexts::EMPTY, $langcode));
    }
    if (mb_strlen($frage) > self::MAX_LENGTH) {
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::hintHtml(AnswerTexts::TOO_LONG, $langcode));
    }
    if (!$this->configFactory->get('tannwald_assistant.settings')->get('assistant_enabled') || !$this->limitCheck->allowsAnswer($account)) {
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_LIMIT, AnswerTexts::hintHtml(AnswerTexts::UNAVAILABLE, $langcode));
    }
    $result = $this->retriever->retrieve($frage, $account, $maschineNid);
    return $this->respond($start, $frage, $result, $account, $session, $result->langcode);
  }

  /**
   * Antwortet auf einem fertigen RetrievalResult (Evals 4.3, Hand-Läufe).
   *
   * Derselbe Weg wie answer() ab „keine Treffer“; ohne Längen-, Schalter-
   * und Limit-Prüfung.
   */
  public function answerWithContext(string $frage, RetrievalResult $result, AccountInterface $account, string $session, ?string $langcode = NULL): AnswerResult {
    return $this->respond(hrtime(TRUE), trim($frage), $result, $account, $session, $langcode ?? $result->langcode);
  }

  /**
   * Gemeinsamer Weg ab dem Retrieval-Ergebnis.
   */
  private function respond(int|float $start, string $frage, RetrievalResult $result, AccountInterface $account, string $session, string $langcode): AnswerResult {
    if ($result->isEmpty()) {
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::refusalHtml($langcode));
    }

    if ($this->safetyDetector->isSafety($frage, $result, $langcode)) {
      $template = $this->promptBuilder->safetyAnswer($result, $langcode);
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_SAFETY, $template['html'], self::sources($result, $template['citations']), [], TRUE);
    }

    $settings = $this->configFactory->get('tannwald_assistant.settings');
    $model = (string) $settings->get('chat_model');
    $input = $this->promptBuilder->build($frage, $result, $langcode, FALSE);
    try {
      $provider = $this->aiProvider->createInstance((string) $settings->get('provider'));
      $provider->setConfiguration([
        'temperature' => PromptBuilder::TEMPERATURE,
        'max_tokens' => PromptBuilder::MAX_TOKENS,
      ]);
      $output = $provider->chat($input, $model, ['tannwald_assistant']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Chat provider failed: @message', ['@message' => $e->getMessage()]);
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_LIMIT, AnswerTexts::hintHtml(AnswerTexts::UNAVAILABLE, $langcode));
    }

    $tokens = self::tokens($output);
    $costPer1k = (float) ($settings->get('kosten_pro_1k_chf') ?? 0);
    $this->usageRecorder->record($model, 'chat', $tokens['prompt'], $tokens['completion'], 'assistent', round(($tokens['prompt'] + $tokens['completion']) / 1000 * $costPer1k, 4));

    $normalized = $output->getNormalized();
    $text = $normalized instanceof ChatMessage ? $normalized->getText() : '';
    $check = $this->citationValidator->validate($text, $result);
    $html = $check->isAnswered() ? $this->outputFilter->filter($check->text) : '';
    $substance = trim(preg_replace('/\[\d+\]/', '', strip_tags($html)) ?? '');
    if (!$check->isAnswered() || $substance === '') {
      return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_REFUSED, AnswerTexts::refusalHtml($langcode), [], $tokens);
    }
    return $this->finish($start, $frage, $account, $session, $langcode, AnswerResult::STATUS_ANSWERED, $html, self::sources($result, $check->citations), $tokens);
  }

  /**
   * Schreibt die Log-Zeile und baut das Ergebnis.
   */
  private function finish(int|float $start, string $frage, AccountInterface $account, string $session, string $langcode, string $status, string $html, array $quellen = [], array $tokens = [], bool $safety = FALSE): AnswerResult {
    $dauerMs = (int) round((hrtime(TRUE) - $start) / 1e6);
    $salt = Settings::getHashSalt();
    $logId = (int) $this->database->insert(self::LOG_TABLE)
      ->fields([
        'session_hash' => hash('sha256', $session . $salt),
        'uid_hash' => hash('sha256', $account->id() . $salt),
        'langcode' => $langcode,
        'frage' => mb_substr($frage, 0, 2 * self::MAX_LENGTH),
        'antwort' => $html,
        'quellen' => json_encode(array_values($quellen), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'status' => $status,
        'dauer_ms' => $dauerMs,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    return new AnswerResult($status, $html, $quellen, $logId, $dauerMs, $tokens, $langcode, $safety);
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
      ];
    }
    return $sources;
  }

  /**
   * Tokens aus der Provider-Antwort.
   *
   * Der Ollama-Provider spricht die OpenAI-kompatible Schnittstelle (/v1):
   * usage.prompt_tokens/completion_tokens landen im TokenUsageDto; die
   * nativen Ollama-Felder prompt_eval_count/eval_count sind der Rückfall.
   *
   * @return array{prompt: int, completion: int}
   *   Tokens.
   */
  private static function tokens(ChatOutput $output): array {
    $usage = $output->getTokenUsage();
    $raw = $output->getRawOutput();
    $raw = is_array($raw) ? $raw : [];
    return [
      'prompt' => (int) ($usage->input ?? $raw['usage']['prompt_tokens'] ?? $raw['prompt_eval_count'] ?? 0),
      'completion' => (int) ($usage->output ?? $raw['usage']['completion_tokens'] ?? $raw['eval_count'] ?? 0),
    ];
  }

}
