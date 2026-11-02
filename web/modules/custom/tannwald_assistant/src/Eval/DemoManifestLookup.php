<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

/**
 * Manifest-Auflösung über B's tannwald_demo.lookup (DemoLookup::nidOf()).
 *
 * Optional: ohne tannwald_demo (Seeder) gibt es keine Demo-Inhalte und damit
 * keine Evals — dann eine klare Meldung statt eines Fatals.
 */
final class DemoManifestLookup implements ManifestLookupInterface {

  public function __construct(
    private readonly ?object $demoLookup = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function nidOf(string $manifestId): ?int {
    if ($this->demoLookup === NULL || !method_exists($this->demoLookup, 'nidOf')) {
      throw new \RuntimeException('tannwald_demo.lookup is not available: enable tannwald_demo and run the seeder first.');
    }
    $nid = $this->demoLookup->nidOf($manifestId);
    return $nid === NULL ? NULL : (int) $nid;
  }

}
