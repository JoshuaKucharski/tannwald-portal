<?php

/**
 * @file
 * Styleguide (/styleguide, nur Split dev, Aufgabe 1.1): Tokens, Kontrast, Icons.
 *
 * Die Route liefert C's Controller in tannwald_demo (#theme
 * tannwald_styleguide);
 * Hook, Variablen und Template gehören dem Theme. Die Kontrastwerte werden hier
 * nach der WCAG-Formel berechnet, damit Styleguide und Bericht dieselbe Messung
 * zeigen (ddev drush php:eval ruft _tannwald_contrast_table() direkt).
 */

declare(strict_types=1);

/**
 * Farb-Tokens v2 (Name → Hex), identisch mit src/tailwind.css @theme.
 *
 * @return array<string, string>
 *   Token-Name ohne Präfix --color- → Hex.
 */
function _tannwald_color_tokens(): array {
  return [
    'accent' => '#1F4E79',
    'accent-hover' => '#15385A',
    'accent-active' => '#102A44',
    'ink' => '#1C2024',
    'muted' => '#5B6470',
    'surface' => '#F3F4F2',
    'panel' => '#FFFFFF',
    'chat' => '#F8F9F8',
    'line' => '#D6DAE0',
    'line-soft' => '#ECEEF1',
    'control' => '#848E9B',
    'bar' => '#1C2024',
    'bar-muted' => '#C9CED6',
    'ok' => '#1E6B3A',
    'ok-bg' => '#E6F2EA',
    'warn' => '#7A3508',
    'warn-strong' => '#B4530A',
    'warn-bg' => '#FBEFE4',
    'warn-line' => '#E3B48A',
    'danger' => '#A32D2D',
    'danger-bg' => '#FBEAEA',
    'dualuse' => '#4A2378',
    'dualuse-bg' => '#EEE7F7',
    'private' => '#1F4E79',
    'private-bg' => '#E8EEF5',
    'mark' => '#FFF3B0',
  ];
}

/**
 * Relative Leuchtdichte einer Hex-Farbe (WCAG 2.x).
 */
function _tannwald_luminance(string $hex): float {
  $hex = ltrim($hex, '#');
  $channels = [];
  foreach ([0, 2, 4] as $offset) {
    $value = hexdec(substr($hex, $offset, 2)) / 255;
    $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
  }
  return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Kontrastverhältnis zweier Hex-Farben (1 … 21), auf zwei Stellen gerundet.
 */
function _tannwald_contrast(string $foreground, string $background): float {
  $l1 = _tannwald_luminance($foreground);
  $l2 = _tannwald_luminance($background);
  $lighter = max($l1, $l2);
  $darker = min($l1, $l2);
  return round(($lighter + 0.05) / ($darker + 0.05), 2);
}

/**
 * Token-Paare, die im Portal aufeinander liegen; Mindestwert Text 4.5, UI 3.
 *
 * @return array<int, array{use: string, fg: string, bg: string, fg_hex: string, bg_hex: string, ratio: float, min: float, ok: bool, kind: string}>
 *   Messtabelle.
 */
function _tannwald_contrast_table(): array {
  $colors = _tannwald_color_tokens();
  $pairs = [
    // Text (≥ 4.5:1).
    ['Text on panel (cards, forms)', 'ink', 'panel', 4.5],
    ['Text on surface (page)', 'ink', 'surface', 4.5],
    ['Meta on panel', 'muted', 'panel', 4.5],
    ['Meta on surface', 'muted', 'surface', 4.5],
    ['Link, text button on panel', 'accent', 'panel', 4.5],
    ['Link on surface', 'accent', 'surface', 4.5],
    ['Primary button (rest)', 'panel', 'accent', 4.5],
    ['Primary button (hover)', 'panel', 'accent-hover', 4.5],
    ['Primary button (active)', 'panel', 'accent-active', 4.5],
    ['Warn button (Gelesen bestätigen)', 'panel', 'warn-strong', 4.5],
    ['Selected chip, active navigation', 'accent', 'private-bg', 4.5],
    ['Badge ok', 'ok', 'ok-bg', 4.5],
    ['Badge, notice warn', 'warn', 'warn-bg', 4.5],
    ['Badge, notice danger', 'danger', 'danger-bg', 4.5],
    ['Badge dual-use', 'dualuse', 'dualuse-bg', 4.5],
    ['Badge private (Nur <Firma>)', 'private', 'private-bg', 4.5],
    ['Search highlight', 'ink', 'mark', 4.5],
    ['Demo bar text', 'panel', 'bar', 4.5],
    ['Demo bar muted', 'bar-muted', 'bar', 4.5],
    ['Brand panel text (login)', 'panel', 'accent', 4.5],
    ['Text on chat surface', 'ink', 'chat', 4.5],
    ['Meta on chat surface', 'muted', 'chat', 4.5],
    // UI (≥ 3:1): Ränder von Bedienelementen, Fokusring, Icons.
    ['Control border on panel (input, secondary button, chip)', 'control', 'panel', 3],
    ['Control border on surface', 'control', 'surface', 3],
    ['Focus ring on panel', 'accent', 'panel', 3],
    ['Focus ring on surface', 'accent', 'surface', 3],
    ['Focus ring on accent (brand panel)', 'panel', 'accent', 3],
    ['Icon muted on panel', 'muted', 'panel', 3],
    ['Status ok icon on panel', 'ok', 'panel', 3],
    ['Status warn-strong icon on panel', 'warn-strong', 'panel', 3],
    ['Status danger icon on panel', 'danger', 'panel', 3],
    ['Placeholder mark on line-soft', 'line', 'line-soft', 1],
  ];
  $table = [];
  foreach ($pairs as [$use, $fg, $bg, $min]) {
    $ratio = _tannwald_contrast($colors[$fg], $colors[$bg]);
    $table[] = [
      'use' => $use,
      'fg' => $fg,
      'bg' => $bg,
      'fg_hex' => $colors[$fg],
      'bg_hex' => $colors[$bg],
      'ratio' => $ratio,
      'min' => $min,
      'ok' => $ratio >= $min,
      'kind' => $min >= 4.5 ? 'text' : ($min >= 3 ? 'ui' : 'decorative'),
    ];
  }
  return $table;
}

/**
 * Variablen für templates/styleguide.html.twig.
 */
function _tannwald_styleguide_prepare(array &$variables): void {
  $themePath = \Drupal::service('extension.list.theme')->getPath('tannwald');
  $variables['hero_url'] = base_path() . $themePath . '/images/hero-login.webp';
  $variables['colors'] = _tannwald_color_tokens();
  $variables['contrast'] = _tannwald_contrast_table();
  $variables['contrast_failed'] = count(array_filter($variables['contrast'], static fn(array $row): bool => !$row['ok']));
  $variables['type_scale'] = [
    [
      'class' => 'text-3xl',
      'size' => 44,
      'leading' => 1.1,
      'weight' => 600,
      'use' => t('Hero (login brand panel only)'),
    ],
    [
      'class' => 'text-2xl',
      'size' => 32,
      'leading' => 1.2,
      'weight' => 600,
      'use' => t('Display: one page title per page'),
    ],
    [
      'class' => 'text-xl',
      'size' => 22,
      'leading' => 1.3,
      'weight' => 600,
      'use' => t('Title: cards, sections'),
    ],
    [
      'class' => 'text-lg',
      'size' => 17,
      'leading' => 1.5,
      'weight' => 400,
      'use' => t('Lead: document body, intro lines'),
    ],
    [
      'class' => 'text-base',
      'size' => 15,
      'leading' => 1.5,
      'weight' => 400,
      'use' => t('Text: interface, forms, tables'),
    ],
    [
      'class' => 'text-sm',
      'size' => 13,
      'leading' => 1.5,
      'weight' => 400,
      'use' => t('Meta: dates, versions, badges, help'),
    ],
  ];
  $variables['spaces'] = [4, 8, 12, 16, 24, 32, 48, 64];
  $variables['icons'] = [
    'download', 'search', 'arrow', 'check', 'warning', 'info', 'close', 'menu',
    'chat', 'document', 'machine', 'bulletin', 'language', 'filter', 'sort',
    'external',
  ];
  $variables['#cache']['max-age'] = 0;
}
