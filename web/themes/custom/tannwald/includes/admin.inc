<?php

/**
 * @file
 * Admin-Views „Nutzung und Kosten“ im Portal-Theme (1.3): Zeitraum-Chips.
 */

declare(strict_types=1);

use Drupal\Core\Url;

/**
 * Zeitraum-Chips der View admin_nutzung (exponierter Filter „zeitraum“).
 *
 * Werte sind Offsets des Views-Datumsfilters (Typ offset), Vorgabe -30 days.
 *
 * @return array<int, array{label: mixed, url: string, active: bool}>
 *   Chips 30 / 90 / 365 Tage.
 */
function _tannwald_admin_period_chips(): array {
  $request = \Drupal::request();
  $current = (string) ($request->query->get('zeitraum') ?? '-30 days');
  $periods = [
    '-30 days' => t('30 days'),
    '-90 days' => t('90 days'),
    '-365 days' => t('365 days'),
  ];
  $chips = [];
  foreach ($periods as $value => $label) {
    $chips[] = [
      'label' => $label,
      'url' => Url::fromRoute('<current>', [], ['query' => ['zeitraum' => $value]])->toString(),
      'active' => $current === $value,
    ];
  }
  return $chips;
}

/**
 * Variablen für views-view--admin-nutzung.html.twig.
 */
function _tannwald_admin_nutzung_prepare(array &$variables): void {
  $variables['periods'] = _tannwald_admin_period_chips();
}
