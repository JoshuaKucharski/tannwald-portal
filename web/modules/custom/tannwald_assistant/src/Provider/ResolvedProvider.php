<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Provider;

/**
 * Ergebnis von ProviderResolver::resolve(): Plugin, Rohmodell, Dimension.
 */
final class ResolvedProvider {

  /**
   * Hält das Ergebnis einer Auflösung.
   *
   * @param string $providerId
   *   Plugin-ID des AI-Moduls (ollama|dashscope).
   * @param string $operation
   *   Die Operation: chat oder embeddings.
   * @param string $model
   *   Rohname des Modells beim Provider (z. B. qwen-plus, bge-m3).
   * @param object $provider
   *   ProviderProxy des AI-Moduls (frische Instanz je Aufruf).
   * @param int $dimensions
   *   Vektorgrösse (nur Embeddings sinnvoll; sonst die Vorgabe des Providers).
   * @param float $costPer1k
   *   Kosten je 1'000 Tokens in CHF (Chat).
   */
  public function __construct(
    public readonly string $providerId,
    public readonly string $operation,
    public readonly string $model,
    public readonly object $provider,
    public readonly int $dimensions,
    public readonly float $costPer1k,
  ) {}

}
