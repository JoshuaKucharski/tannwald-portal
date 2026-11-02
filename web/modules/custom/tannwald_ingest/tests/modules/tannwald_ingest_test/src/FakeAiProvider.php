<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest_test;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;

/**
 * Chat- und Embedding-Double (deterministisch, nie Ollama).
 */
final class FakeAiProvider {

  /**
   * Wie ProviderProxy::setConfiguration().
   */
  public function setConfiguration(array $configuration): void {}

  /**
   * Modell-IDs → Rohnamen.
   */
  public function getConfiguredModels(?string $operation_type = NULL): array {
    return ['qwen2_5_7b' => 'qwen2.5:7b', 'bge_m3_latest' => 'bge-m3:latest'];
  }

  /**
   * Deutsche Zusammenfassung, die den Dokumenttitel nennt.
   */
  public function chat(ChatInput $input, string $model, array $tags = []): ChatOutput {
    preg_match('/titel="([^"]*)"/', $input->getMessages()[0]->getText(), $m);
    $text = 'Zusammenfassung von ' . ($m[1] ?? 'Dokument') . ': Die Anleitung beschreibt den Aufbau und die Wartung der Linie. '
      . 'Sicherheitsrelevant ist Kapitel 2 Sicherheit, das vor der Reinigung das Stromlosschalten verlangt.';
    return new ChatOutput(new ChatMessage('assistant', $text), ['model' => $model], [], new TokenUsageDto(input: 800, output: 60));
  }

  /**
   * Vektoren aus dem Text-Hash.
   */
  public function embeddingsCollection(EmbeddingsCollectionInput $input, string $model, array $tags = []): EmbeddingsCollectionOutput {
    return new EmbeddingsCollectionOutput(array_map([self::class, 'vector'], $input->getPrompts()), ['model' => 'bge-m3:latest'], []);
  }

  /**
   * Deterministischer 1024er-Vektor (wie SummaryFixturesTrait::hashVector()).
   *
   * @return float[]
   *   Vektor.
   */
  public static function vector(string $text): array {
    $seed = crc32($text);
    $vector = [];
    for ($i = 0; $i < 1024; $i++) {
      $vector[] = sin($seed + $i * 0.37);
    }
    return $vector;
  }

}
