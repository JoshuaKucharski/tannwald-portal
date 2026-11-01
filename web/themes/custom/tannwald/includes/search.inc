<?php

/**
 * @file
 * Suche-Seite (Wireframe 02): Preprocess für tannwald_search_page (4.2).
 */

declare(strict_types=1);

use Drupal\Core\Url;

/**
 * Implements hook_preprocess_HOOK() for tannwald_search_page.
 *
 * Ergänzt E's Variablen (Vertrag (a) in Bericht 5.2): form_id, search_url,
 * modus_label, duration_text, active_filters, facet_groups, reset_all_url,
 * maschine_nid, examples, has_query.
 */
function tannwald_preprocess_tannwald_search_page(array &$variables): void {
  $request = \Drupal::request();
  $query = (string) ($variables['query'] ?? '');
  $modus = ($variables['modus'] ?? 'hybrid') === 'volltext' ? 'volltext' : 'hybrid';
  $filters = is_array($variables['filters'] ?? NULL) ? $variables['filters'] : [];
  $facets = is_array($variables['facets'] ?? NULL) ? $variables['facets'] : [];
  $maschineNid = $request->query->get('maschine_nid');
  $maschineNid = is_numeric($maschineNid) ? (int) $maschineNid : NULL;

  // Basisparameter, die jeder Link der Seite behält.
  $base = ['q' => $query, 'modus' => $modus];
  if (!empty($variables['aktuell'])) {
    $base['aktuell'] = 1;
  }
  if ($maschineNid !== NULL) {
    $base['maschine_nid'] = $maschineNid;
  }

  $labels = [
    'maschinentyp' => t('Machine type'),
    'dokumenttyp' => t('Document type'),
    'sprache' => t('Language', [], ['context' => 'tannwald facet']),
  ];
  $active = 0;
  $groups = [];
  foreach (['maschinentyp', 'dokumenttyp', 'sprache'] as $name) {
    $items = is_array($facets[$name] ?? NULL) ? array_values($facets[$name]) : [];
    $activeHere = count(array_filter($items, static fn(array $item): bool => !empty($item['active'])));
    $active += $activeHere;
    // Zurücksetzen dieser Gruppe: alle anderen Filter bleiben.
    $others = $base;
    foreach ($filters as $other => $values) {
      if ($other !== $name && is_array($values) && $values !== []) {
        $others[$other] = array_values($values);
      }
    }
    $groups[] = [
      'name' => $name,
      'label' => $labels[$name],
      'param' => $name . '[]',
      'items' => $items,
      'reset_url' => _tannwald_search_url($others),
    ];
  }

  $variables['form_id'] = 'tannwald-search-form';
  $variables['search_url'] = _tannwald_search_url([]);
  $variables['modus_label'] = $modus === 'volltext' ? t('Full text only') : t('Hybrid');
  $variables['duration_text'] = number_format(((int) ($variables['dauer_ms'] ?? 0)) / 1000, 2, '.', '');
  $variables['active_filters'] = $active;
  $variables['facet_groups'] = $groups;
  $variables['reset_all_url'] = _tannwald_search_url($base);
  $variables['maschine_nid'] = $maschineNid;
  $variables['has_query'] = trim($query) !== '';
  $example = (string) t('clean the sealing station');
  $variables['examples'] = [
    [
      'label' => 'E-4711',
      'url' => _tannwald_search_url(['q' => 'E-4711', 'modus' => $modus]),
    ],
    [
      'label' => $example,
      'url' => _tannwald_search_url(['q' => $example, 'modus' => $modus]),
    ],
  ];
}

/**
 * URL der Suche mit Query-Parametern (Route von 4.2, Rückfall /suche).
 */
function _tannwald_search_url(array $query): string {
  try {
    return Url::fromRoute('tannwald_assistant.search', [], ['query' => $query])->toString();
  }
  catch (\Exception) {
    return Url::fromUserInput('/suche', ['query' => $query])->toString();
  }
}
