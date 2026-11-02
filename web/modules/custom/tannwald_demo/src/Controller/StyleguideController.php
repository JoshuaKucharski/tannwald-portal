<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Styleguide des Themes (nur Split dev, Aufgabe 1.1).
 *
 * Liefert den Theme-Hook tannwald_styleguide aus; Inhalt, Variablen und
 * Template gehören dem Theme (includes/styleguide.inc,
 * templates/styleguide.html.twig).
 */
final class StyleguideController extends ControllerBase {

  /**
   * Seite /styleguide.
   */
  public function page(): array {
    return [
      '#theme' => 'tannwald_styleguide',
      '#cache' => ['max-age' => 0],
    ];
  }

}
