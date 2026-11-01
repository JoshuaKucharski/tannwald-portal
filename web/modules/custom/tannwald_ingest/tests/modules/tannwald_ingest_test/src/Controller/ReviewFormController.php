<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest_test\Controller;

use Drupal\node\NodeInterface;

/**
 * Testseite: rendert das Review-Formular wie 5.3 in review-actions.
 */
final class ReviewFormController {

  /**
   * Seite mit tannwald_ingest_review_form().
   */
  public function form(NodeInterface $node): array {
    return tannwald_ingest_review_form($node);
  }

}
