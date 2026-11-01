<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Testseite: Banner-Daten und Formular wie in der Komponente bulletin-banner.
 */
final class BannerController extends ControllerBase {

  /**
   * Seite mit den Banner-Daten als Text und dem Formular.
   *
   * @return array<string, mixed>
   *   Render-Array.
   */
  public function page(NodeInterface $bulletin, NodeInterface $maschine): array {
    $data = tannwald_bulletin_banner_data($bulletin, $maschine, $this->currentUser());
    $text = sprintf(
      'banner id=%s titel=%s art=%s pflicht=%s bestaetigt_am=%s durch=%s url=%s',
      $data['id'],
      $data['titel'],
      $data['art'],
      $data['bestaetigung_pflicht'] ? 'ja' : 'nein',
      $data['bestaetigt_am'] === NULL ? 'none' : date('d.m.Y', $data['bestaetigt_am']),
      $data['bestaetigt_durch_uid'] ?? 'none',
      $data['url_bulletin'],
    );
    return [
      'info' => ['#plain_text' => $text],
      'form' => $data['form'] ?? ['#markup' => '<p>no form</p>'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
