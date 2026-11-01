<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Embedding;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
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

  public function __construct(
    protected AiProviderPluginManager $providerManager,
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
    $provider = $this->providerManager->createInstance($providerId);
    $keys = array_keys($texts);
    $vectors = [];
    foreach (array_chunk(array_values($texts), self::BATCH_SIZE) as $batch) {
      $batch = array_map('strval', $batch);
      $output = $provider->embeddingsCollection(new EmbeddingsCollectionInput($batch), $model, ['tannwald_ingest']);
      $normalized = $output->getNormalized();
      if (count($normalized) !== count($batch)) {
        throw new \RuntimeException(sprintf('Embedding provider %s returned %d vectors for %d texts.', $providerId, count($normalized), count($batch)));
      }
      foreach ($normalized as $vector) {
        if (!is_array($vector) || count($vector) !== VectorPack::DIMENSION) {
          throw new \RuntimeException(sprintf('Embedding model %s returned dimension %d, expected %d.', $model, is_array($vector) ? count($vector) : 0, VectorPack::DIMENSION));
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
   * Provider aus ai.settings, Modell aus tannwald_assistant.settings (falls da).
   *
   * @return string[]
   *   [provider_id, model].
   */
  protected function providerAndModel(): array {
    $default = $this->providerManager->getDefaultProviderForOperationType('embeddings');
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
