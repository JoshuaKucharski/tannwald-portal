<?php

declare(strict_types=1);

namespace Drupal\tannwald_qwen\Plugin\AiProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\tannwald_qwen\SecretScrubber;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;

/**
 * Alibaba Cloud Model Studio (DashScope) im OpenAI-kompatiblen Modus.
 *
 * Chat (`POST {endpoint}/chat/completions`, ohne Streaming) und Embeddings
 * (`POST {endpoint}/embeddings` mit `dimensions` und `encoding_format`).
 * Endpoint: `tannwald_qwen.settings:endpoint`, in settings.php aus
 * DASHSCOPE_ENDPOINT_EU überschrieben. Schlüssel: Key-Entity aus
 * `tannwald_qwen.settings:api_key` (Provider env, DASHSCOPE_API_KEY_EU).
 * Fehlermeldungen verlassen die Klasse nur über SecretScrubber.
 */
#[AiProvider(
  id: 'dashscope',
  label: new TranslatableMarkup('DashScope (Qwen)'),
)]
class DashScopeProvider extends OpenAiBasedProviderClientBase {

  /**
   * Vektorgrösse, wenn der Aufrufer keine `dimensions` setzt (Chunk-Store).
   */
  public const DEFAULT_DIMENSIONS = 1024;

  /**
   * Höchstzahl Texte je Embedding-Aufruf (DashScope text-embedding-v3/v4).
   */
  public const MAX_BATCH = 10;

  /**
   * Höchstzahl Tokens je Text (text-embedding-v3/v4).
   */
  public const MAX_INPUT_TOKENS = 8192;

  /**
   * Payload-Schlüssel, die ein Embedding-Aufruf mitnimmt.
   */
  public const EMBEDDING_KEYS = ['dimensions', 'encoding_format', 'user'];

  /**
   * Cache-Präfix der Modell-Liste aus GET /models (eine Stunde).
   */
  public const MODELS_CACHE_PREFIX = 'tannwald_qwen:models:';

  /**
   * {@inheritdoc}
   */
  protected bool $hasPredefinedModels = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat', 'embeddings'];
  }

  /**
   * {@inheritdoc}
   *
   * Keine Streaming-Fähigkeit: der Assistent braucht die ganze Antwort samt
   * `usage`, und so streamt die Basisklasse auch in einer Fiber nicht.
   */
  public function getSupportedCapabilities(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   *
   * Nutzbar nur mit Endpoint und gesetztem Schlüssel.
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if ($this->getConfiguredEndpoint() === '' || !$this->hasKeyValue()) {
      return FALSE;
    }
    return $operation_type === NULL || in_array($operation_type, $this->getSupportedOperationTypes(), TRUE);
  }

  /**
   * Ob der Schlüssel einen Wert hat; der Wert selbst verlässt die Methode nie.
   */
  public function hasKeyValue(): bool {
    if ($this->apiKey !== '') {
      return TRUE;
    }
    $keyId = (string) ($this->getConfig()->get('api_key') ?? '');
    if ($keyId === '') {
      return FALSE;
    }
    return (string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? '') !== '';
  }

  /**
   * Der konfigurierte Endpoint ohne abschliessenden Schrägstrich.
   */
  public function getConfiguredEndpoint(): string {
    return rtrim(trim((string) ($this->getConfig()->get('endpoint') ?? '')), '/');
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    if (empty($this->client)) {
      $endpoint = $this->getConfiguredEndpoint();
      if ($endpoint === '') {
        throw new AiSetupFailureException('DashScope endpoint is not configured (environment variable DASHSCOPE_ENDPOINT_EU is empty).');
      }
      if (!$this->hasKeyValue()) {
        throw new AiSetupFailureException(sprintf('DashScope API key is not set (key %s, environment variable DASHSCOPE_API_KEY_EU is empty).', (string) $this->getConfig()->get('api_key')));
      }
      $this->setEndpoint($endpoint);
    }
    parent::loadClient();
  }

  /**
   * {@inheritdoc}
   *
   * Modelle aus GET /models, nach Operation gefiltert. Der EU-Endpoint listet
   * keine Embedding-Modelle (gemessen 2026-11-02); dann bleibt die Liste leer
   * und der Aufrufer nennt das Modell ausdrücklich.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    $models = [];
    foreach ($this->listModelIds() as $id) {
      $embedding = str_contains($id, 'embedding');
      if ($operation_type === 'embeddings' && !$embedding) {
        continue;
      }
      if ($operation_type === 'chat' && ($embedding || preg_match('/(image|ocr|^qwen-mt-)/', $id))) {
        continue;
      }
      $models[$id] = $id;
    }
    return $models;
  }

  /**
   * Alle Modell-IDs des Endpoints, sortiert, eine Stunde gecacht.
   *
   * @return string[]
   *   Modell-IDs; leer, wenn der Endpoint nicht antwortet.
   */
  public function listModelIds(): array {
    $cid = self::MODELS_CACHE_PREFIX . hash('xxh64', $this->getConfiguredEndpoint());
    if ($cached = $this->cacheBackend->get($cid)) {
      return $cached->data;
    }
    try {
      $this->loadClient();
      $list = $this->client->models()->list()->toArray();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('tannwald_qwen')->warning('@message', ['@message' => $this->failureMessage($e)]);
      return [];
    }
    $ids = array_values(array_filter(array_map(static fn(array $m): string => $m['id'], $list['data'])));
    sort($ids);
    $this->cacheBackend->set($cid, $ids, time() + 3600);
    return $ids;
  }

  /**
   * Chat ohne Streaming; setzt `enable_thinking: false` als Vorgabe.
   *
   * Qwen3-Hybridmodelle denken sonst; ohne Streaming verlangt DashScope
   * `enable_thinking: false`.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   Die Eingabe (ChatInput mit System- und Nutzerrolle).
   * @param string $model_id
   *   Rohname des Modells, z. B. qwen-plus.
   * @param array $tags
   *   Tags des Aufrufs.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   Antwort samt Token-Zahlen aus `usage`.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->configuration += ['enable_thinking' => FALSE];
    return parent::chat($input, $model_id, $tags);
  }

  /**
   * {@inheritdoc}
   *
   * Mehr als MAX_BATCH Texte werden aufgeteilt; Vektoren in Eingabefolge,
   * `usage` summiert.
   */
  public function embeddingsCollection(EmbeddingsCollectionInput $input, string $model_id, array $tags = []): EmbeddingsCollectionOutput {
    $batches = array_chunk(array_values($input->getPrompts()), self::MAX_BATCH);
    if (count($batches) <= 1) {
      return $this->embedBatch($input, $model_id, $tags);
    }
    $vectors = [];
    $usage = ['prompt_tokens' => 0, 'total_tokens' => 0];
    foreach ($batches as $batch) {
      $output = $this->embedBatch(new EmbeddingsCollectionInput($batch), $model_id, $tags);
      foreach ($output->getNormalized() as $vector) {
        $vectors[] = $vector;
      }
      $raw = $output->getRawOutput();
      $usage['prompt_tokens'] += (int) ($raw['usage']['prompt_tokens'] ?? 0);
      $usage['total_tokens'] += (int) ($raw['usage']['total_tokens'] ?? 0);
    }
    return new EmbeddingsCollectionOutput($vectors, [
      'object' => 'list',
      'model' => $model_id,
      'usage' => $usage,
      'batches' => count($batches),
    ], []);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $saved = $this->configuration;
    $this->configuration = $this->embeddingsConfiguration();
    try {
      return parent::embeddings($input, $model_id, $tags);
    }
    finally {
      $this->configuration = $saved;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return (int) ($this->configuration['dimensions'] ?? self::DEFAULT_DIMENSIONS);
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
    return self::MAX_INPUT_TOKENS;
  }

  /**
   * Ein Aufruf mit höchstens MAX_BATCH Texten.
   */
  protected function embedBatch(EmbeddingsCollectionInput $input, string $model_id, array $tags): EmbeddingsCollectionOutput {
    $saved = $this->configuration;
    $this->configuration = $this->embeddingsConfiguration();
    try {
      return parent::embeddingsCollection($input, $model_id, $tags);
    }
    finally {
      $this->configuration = $saved;
    }
  }

  /**
   * Payload-Ergänzung für Embeddings: nur erlaubte Schlüssel, mit Vorgaben.
   */
  protected function embeddingsConfiguration(): array {
    $config = array_intersect_key($this->configuration, array_flip(self::EMBEDDING_KEYS));
    return $config + ['dimensions' => self::DEFAULT_DIMENSIONS, 'encoding_format' => 'float'];
  }

  /**
   * {@inheritdoc}
   */
  protected function handleApiException(\Exception $e): void {
    $this->handleApiThrowable($e);
  }

  /**
   * {@inheritdoc}
   *
   * Jede Ausnahme wird durch eine bereinigte ersetzt (ohne `previous`, damit
   * keine Rohmeldung mitreist) und einmal ins Log `tannwald_qwen` geschrieben.
   */
  protected function handleApiThrowable(\Throwable $e): void {
    [$status, $code] = self::describeFailure($e);
    $message = $this->failureMessage($e);
    $this->loggerFactory->get('tannwald_qwen')->warning('@message', ['@message' => $message]);
    $quota = (bool) preg_match('/quota|arrearage|insufficient/i', $code);
    throw match (TRUE) {
      $status === 429 => new AiRateLimitException($message, 429),
      $quota => new AiQuotaException($message, $status),
      $status === 401 || $status === 403 => new AiSetupFailureException($message, $status),
      $status >= 500, $e instanceof \Error, $e instanceof UnserializableResponse => new AiResponseErrorException($message, $status),
      default => new AiRequestErrorException($message, $status),
    };
  }

  /**
   * Bereinigte Meldung: Status, Fehlercode, Text — ohne Schlüssel.
   */
  public function failureMessage(\Throwable $e): string {
    [$status, $code, $detail] = self::describeFailure($e);
    $text = sprintf('DashScope request failed (HTTP %s%s): %s', $status > 0 ? (string) $status : 'n/a', $code !== '' ? ', ' . $code : '', $detail);
    return SecretScrubber::scrub($text, [$this->apiKey]);
  }

  /**
   * Status, Fehlercode und Text einer Ausnahme des OpenAI-Clients.
   *
   * @return array{0: int, 1: string, 2: string}
   *   [HTTP-Status oder 0, Fehlercode, Text] — noch nicht bereinigt.
   */
  public static function describeFailure(\Throwable $e): array {
    return match (TRUE) {
      $e instanceof ErrorException => [$e->getStatusCode(), (string) ($e->getErrorCode() ?? ''), $e->getErrorMessage()],
      $e instanceof RateLimitException => [429, 'rate_limit', $e->getMessage()],
      $e instanceof ServerException => [$e->response->getStatusCode(), 'server_error', $e->getMessage()],
      $e instanceof UnserializableResponse => [
        $e->response->getStatusCode(),
        'unserializable',
        'The response could not be decoded.',
      ],
      $e instanceof TransporterException => [
        0,
        preg_match('/cURL error 28|timed out/i', $e->getMessage()) ? 'timeout' : 'transport',
        $e->getMessage(),
      ],
      default => [(int) $e->getCode(), '', $e->getMessage()],
    };
  }

}
