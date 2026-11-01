<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

/**
 * Manifest-ID (ba-tb200, SB-2026-07) → nid.
 */
interface ManifestLookupInterface {

  /**
   * Node-ID zur Manifest-ID oder NULL.
   */
  public function nidOf(string $manifestId): ?int;

}
