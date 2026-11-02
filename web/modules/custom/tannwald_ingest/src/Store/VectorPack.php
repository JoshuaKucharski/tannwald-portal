<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Store;

/**
 * Packt Embeddings als float32 Little Endian (1024 Werte = 4096 Bytes).
 */
final class VectorPack {

  /**
   * Dimension der Embeddings (bge-m3).
   */
  public const DIMENSION = 1024;

  /**
   * Packt einen Vektor in einen Binärstring.
   *
   * @param float[] $vector
   *   Genau DIMENSION Werte.
   */
  public static function pack(array $vector): string {
    if (count($vector) !== self::DIMENSION) {
      throw new \InvalidArgumentException(sprintf('Embedding has %d values, expected %d.', count($vector), self::DIMENSION));
    }
    return pack('g*', ...array_map('floatval', array_values($vector)));
  }

  /**
   * Entpackt einen Binärstring zu einem Vektor (0-basierte Schlüssel).
   *
   * @return float[]
   *   DIMENSION Werte.
   */
  public static function unpack(string $blob): array {
    if (strlen($blob) !== self::DIMENSION * 4) {
      throw new \InvalidArgumentException(sprintf('Embedding blob has %d bytes, expected %d.', strlen($blob), self::DIMENSION * 4));
    }
    // unpack() liefert 1-basierte Schlüssel.
    return array_values(unpack('g*', $blob) ?: []);
  }

}
