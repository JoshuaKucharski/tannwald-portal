<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant_test;

use Drupal\Core\State\StateInterface;
use Drupal\tannwald_ingest\Embedding\EmbeddingService;

/**
 * Feste Einheitsvektoren statt bge-m3.
 *
 * State tannwald_assistant_test.vectors: {Teilstring => Achse}; ein Text, der
 * den Teilstring enthält, bekommt die Einheitsachse, sonst DEFAULT_AXIS.
 */
final class FakeEmbeddingService extends EmbeddingService {

  public const DIMENSION = 1024;

  public const DEFAULT_AXIS = 1023;

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function embed(array $texts, string $kontext = 'ingest'): array {
    $map = (array) $this->state->get('tannwald_assistant_test.vectors', []);
    $vectors = [];
    foreach ($texts as $text) {
      $axis = self::DEFAULT_AXIS;
      foreach ($map as $needle => $candidate) {
        if (mb_stripos((string) $text, (string) $needle) !== FALSE) {
          $axis = (int) $candidate;
          break;
        }
      }
      $vectors[] = self::axis($axis);
    }
    return $vectors;
  }

  /**
   * Einheitsvektor auf einer Achse.
   *
   * @return float[]
   *   1024 Werte.
   */
  public static function axis(int $axis): array {
    $vector = array_fill(0, self::DIMENSION, 0.0);
    $vector[$axis] = 1.0;
    return $vector;
  }

}
