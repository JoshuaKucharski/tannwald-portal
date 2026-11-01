<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Embedding;

use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\tannwald_ingest\Store\VectorPack;
use Drupal\tannwald_ingest\Usage\UsageRecorder;

/**
 * Embeddings über den Standard-Provider des AI-Moduls, in Batches zu 16.
 */
class EmbeddingService {

  /**
   * Texte je Provider-Aufruf.
   */
  public const BATCH_SIZE = 16;

  /**
   * Aufgelöste Modellnamen je Service-Instanz.
   *
   * @var array<string, string>
   */
  protected array $rawModels = [];

  /**
   * Konstruktor.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $providerManager
   *   ai.provider (AiProviderPluginManager ist final; das Interface erlaubt
   *   Test-Doubles).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Konfiguration (ai.settings, tannwald_assistant.settings).
   * @param \Drupal\tannwald_ingest\Usage\UsageRecorder $usage
   *   Schreibt tannwald_usage.
   */
  public function __construct(
    protected PluginManagerInterface $providerManager,
    protected ConfigFactoryInterface $configFactory,
    protected UsageRecorder $usage,
  ) {}

  /**
   * Berechnet Embeddings.
   *
   * @param string[] $texts
   *   Texte in beliebiger Zahl.
   * @param string $kontext
   *   Kontext für tannwald_usage (Standard ingest; E nutzt assistent).
   *
   * @return float[][]
   *   Je Text ein Vektor mit 1024 Werten, gleiche Reihenfolge und Schlüssel.
   */
  public function embed(array $texts, string $kontext = 'ingest'): array {
    if ($texts === []) {
      return [];
    }
    [$providerId, $model] = $this->providerAndModel();
    // ProviderProxy reicht embeddingsCollection() per __call an das Plugin.
    $provider = $this->providerManager->createInstance($providerId);
    $model = $this->rawModelName($provider, $model);
    $keys = array_keys($texts);
    $vectors = [];
    foreach (array_chunk(array_values($texts), self::BATCH_SIZE) as $batch) {
      $batch = array_map('strval', $batch);
      $output = call_user_func([$provider, 'embeddingsCollection'], new EmbeddingsCollectionInput($batch), $model, ['tannwald_ingest']);
      if (!$output instanceof EmbeddingsCollectionOutput) {
        throw new \RuntimeException(sprintf('Embedding provider %s returned no embeddings collection.', $providerId));
      }
      $normalized = $output->getNormalized();
      if (count($normalized) !== count($batch)) {
        throw new \RuntimeException(sprintf('Embedding provider %s returned %d vectors for %d texts.', $providerId, count($normalized), count($batch)));
      }
      foreach ($normalized as $vector) {
        if (count($vector) !== VectorPack::DIMENSION) {
          throw new \RuntimeException(sprintf('Embedding model %s returned dimension %d, expected %d.', $model, count($vector), VectorPack::DIMENSION));
        }
        $vectors[] = array_map('floatval', array_values($vector));
      }
      $raw = $output->getRawOutput();
      $raw = is_array($raw) ? $raw : [];
      $tokens = $raw['usage']['prompt_tokens'] ?? $raw['prompt_eval_count'] ?? NULL;
      if (!is_numeric($tokens)) {
        // Schätzung, falls der Provider keine Tokens meldet (4 Zeichen/Token).
        $tokens = array_sum(array_map(static fn(string $t): int => (int) ceil(mb_strlen($t) / 4), $batch));
      }
      $modell = (string) preg_replace('/:latest$/', '', (string) ($raw['model'] ?? $model));
      $this->usage->record($modell, 'embedding', (int) $tokens, 0, $kontext);
    }
    return array_combine($keys, $vectors);
  }

  /**
   * Übersetzt eine Drupal-Modell-ID (bge_m3_latest) in den Rohnamen.
   *
   * Gemessen 2026-11-01: embeddingsCollection() des OpenAI-basierten
   * Providers reicht die ID unverändert an Ollama („model 'bge_m3_latest' not
   * found“); nur embeddings() übersetzt. getConfiguredModels() liefert
   * [Maschinenname => Rohname]; Rohnamen bleiben unverändert.
   */
  protected function rawModelName(object $provider, string $model): string {
    if (!isset($this->rawModels[$model])) {
      $models = call_user_func([$provider, 'getConfiguredModels'], 'embeddings');
      $this->rawModels[$model] = is_array($models) && isset($models[$model]) ? (string) $models[$model] : $model;
    }
    return $this->rawModels[$model];
  }

  /**
   * Provider aus ai.settings, Modell aus tannwald_assistant.settings.
   *
   * @return string[]
   *   [provider_id, model].
   */
  protected function providerAndModel(): array {
    // Wie AiProviderPluginManager::getDefaultProviderForOperationType().
    $default = $this->configFactory->get('ai.settings')->get('default_providers.embeddings');
    if (empty($default['provider_id'])) {
      throw new \RuntimeException('No default embeddings provider configured in ai.settings.');
    }
    $model = (string) ($this->configFactory->get('tannwald_assistant.settings')->get('embedding_model') ?: ($default['model_id'] ?? ''));
    if ($model === '') {
      throw new \RuntimeException('No embeddings model configured.');
    }
    return [(string) $default['provider_id'], $model];
  }

}
