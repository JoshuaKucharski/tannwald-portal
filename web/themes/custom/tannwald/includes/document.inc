<?php

/**
 * @file
 * Dokument- und Bulletinseite: Meta, Sprach-Pillen, Anker, Inhaltsverzeichnis.
 */

declare(strict_types=1);

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\tannwald_ingest\HeadingSlug;

/**
 * Variablen für node--dokument.html.twig.
 */
function _tannwald_document_preprocess(array &$variables, NodeInterface $document): void {
  $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
  $docLangcode = $document->language()->getId();
  $export = $document->hasField('field_exportklasse') ? (string) $document->get('field_exportklasse')->value : 'keine';

  $badges = [];
  $typeTerm = $document->hasField('field_dokumenttyp') ? $document->get('field_dokumenttyp')->entity : NULL;
  $typeTerm = $typeTerm instanceof TermInterface ? $typeTerm : NULL;
  if ($typeTerm !== NULL) {
    $badges[] = ['label' => (string) $typeTerm->label(), 'variant' => 'neutral'];
  }
  $machineTypes = [];
  if ($document->hasField('field_maschinentyp')) {
    foreach ($document->get('field_maschinentyp')->referencedEntities() as $machineType) {
      $machineTypes[] = (string) $machineType->label();
    }
  }
  $serial = NULL;
  $serialUrl = NULL;
  if ($export !== '' && $export !== 'keine') {
    $badges[] = [
      'label' => t('Dual-use · export class @n', ['@n' => (int) filter_var($export, FILTER_SANITIZE_NUMBER_INT)]),
      'variant' => 'dualuse',
    ];
  }
  if ($document->hasField('field_seriennummer') && !$document->get('field_seriennummer')->isEmpty()) {
    $machine = $document->get('field_seriennummer')->entity;
    $company = _tannwald_machine_company($machine instanceof NodeInterface ? $machine : NULL);
    if ($company !== NULL) {
      $badges[] = ['label' => t('Only @company', ['@company' => $company]), 'variant' => 'private'];
    }
    if ($machine instanceof NodeInterface && $machine->access('view')) {
      $serial = (string) $machine->label();
      $serialUrl = $machine->toUrl('canonical', ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString();
    }
  }

  $body = _tannwald_body_with_anchors($variables['content']['body'] ?? []);

  // 1.2: Typenschild-Meta, Titelbild (Dokument, sonst Dokumenttyp) und
  // Änderungshistorie als Zeitleiste (aktuelle Version zuerst).
  $variables['type_label'] = $typeTerm ? (string) $typeTerm->label() : NULL;
  $variables['machine_types'] = $machineTypes;
  $variables['serial'] = $serial;
  $variables['serial_url'] = $serialUrl;
  $imageOptions = ['loading' => 'eager'];
  if ($typeTerm !== NULL) {
    $imageOptions['fallback'] = $typeTerm;
  }
  $variables['image'] = function_exists('tannwald_image') ? tannwald_image($document, 'tw_card_800', $imageOptions) : [];
  $variables['has_own_image'] = $document->hasField('field_bild') && !$document->get('field_bild')->isEmpty();
  $variables['history'] = _tannwald_document_history($document, $langcode);

  $variables['badges'] = $badges;
  $variables['version'] = (string) $document->get('field_version')->value;
  $variables['approved'] = _tannwald_date($document->get('field_freigegeben_am')->value);
  $variables['valid_until'] = $document->hasField('field_gueltig_bis') ? _tannwald_date($document->get('field_gueltig_bis')->value) : NULL;
  $variables['language_label'] = _tannwald_language_name($docLangcode);
  $variables['fallback_label'] = $docLangcode !== $langcode ? _tannwald_language_name($docLangcode) : NULL;
  $variables['change_note'] = $document->hasField('field_aenderungsvermerk') ? ($document->get('field_aenderungsvermerk')->value ?: NULL) : NULL;
  $variables['audit_hint'] = $export !== '' && $export !== 'keine';
  $variables['languages'] = _tannwald_language_pills($document, $docLangcode);
  $variables['url_download'] = _tannwald_download_url($document);
  $variables['url_history'] = _tannwald_history_url($document);
  $variables['toc'] = $body['toc'];
  $variables['body'] = $body['html'];
  $variables['has_summary'] = isset($variables['content']['tannwald_summary']['#theme']);
  $variables['cache_bubble'] = _tannwald_cache_bubble(
    ['user', 'user.node_grants:view', 'languages:language_interface'],
    ['node_list:zusammenfassung'],
  );
}

/**
 * Variablen für node--bulletin.html.twig (Anker auch hier für Zitate).
 */
function _tannwald_bulletin_preprocess(array &$variables, NodeInterface $bulletin): void {
  $body = _tannwald_body_with_anchors($variables['content']['body'] ?? []);
  $kind = $bulletin->hasField('field_bulletin_art') ? (string) $bulletin->get('field_bulletin_art')->value : '';
  $variables['kind_label'] = $kind === 'sicherheit' ? t('Safety bulletin') : t('Service bulletin');
  $variables['published'] = _tannwald_date_from_timestamp((int) $bulletin->getCreatedTime());
  $variables['requires_confirmation'] = $bulletin->hasField('field_bestaetigung_pflicht') && (bool) $bulletin->get('field_bestaetigung_pflicht')->value;
  $variables['toc'] = $body['toc'];
  $variables['body'] = $body['html'];
  $variables['cache_bubble'] = _tannwald_cache_bubble(['user', 'user.node_grants:view', 'languages:language_interface'], []);
}

/**
 * Rendert den Body und setzt id="kapitel-<slug>" an jedes h2/h3 (Vertrag g).
 *
 * Vorhandene id-Attribute bleiben; der Slug kommt aus HeadingSlug::of() (3.1)
 * auf dem Überschriftentext; doppelte Anker erhalten -2, -3 … Tabellen werden
 * in <div class="table-wrap"> gelegt (overflow-x-auto, kein Querlauf).
 *
 * @return array{html: \Drupal\Component\Render\MarkupInterface, toc: array<int, array{level: int, text: string, anchor: string}>}
 *   Body mit Ankern und Inhaltsverzeichnis.
 */
function _tannwald_body_with_anchors(array $bodyElement): array {
  $html = $bodyElement === [] ? '' : (string) \Drupal::service('renderer')->render($bodyElement);
  if (trim($html) === '') {
    return ['html' => Markup::create(''), 'toc' => []];
  }
  $dom = Html::load($html);
  $xpath = new \DOMXPath($dom);
  $toc = [];
  $used = [];
  $headings = $xpath->query('//h2|//h3');
  foreach ($headings ?: [] as $heading) {
    if (!$heading instanceof \DOMElement) {
      continue;
    }
    $text = trim(preg_replace('/\s+/u', ' ', $heading->textContent) ?? '');
    $id = $heading->getAttribute('id');
    if ($id === '') {
      $id = 'kapitel-' . _tannwald_heading_slug($text);
    }
    $base = $id;
    $n = 2;
    while (isset($used[$id])) {
      $id = $base . '-' . $n++;
    }
    $used[$id] = TRUE;
    $heading->setAttribute('id', $id);
    $toc[] = ['level' => (int) substr($heading->tagName, 1), 'text' => $text, 'anchor' => $id];
  }
  $tables = $xpath->query('//table');
  foreach ($tables ?: [] as $table) {
    if (!$table instanceof \DOMElement || !$table->parentNode) {
      continue;
    }
    $wrap = $dom->createElement('div');
    $wrap->setAttribute('class', 'table-wrap');
    $table->parentNode->insertBefore($wrap, $table);
    $wrap->appendChild($table);
  }
  return ['html' => Markup::create(Html::serialize($dom)), 'toc' => $toc];
}

/**
 * Slug einer Überschrift: HeadingSlug von 3.1, Rückfall mit derselben Regel.
 */
function _tannwald_heading_slug(string $heading): string {
  if (class_exists(HeadingSlug::class)) {
    return HeadingSlug::of($heading);
  }
  $slug = mb_strtolower(trim($heading));
  $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
  $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
  return trim($slug, '-');
}

/**
 * Zeitleiste der Versionen eines Dokuments (1.2): aktuelle zuerst.
 *
 * @return array<int, array{version: string, date: ?string, note: ?string, url: ?string, current: bool}>
 *   Einträge für tannwald:timeline.
 */
function _tannwald_document_history(NodeInterface $document, string $langcode): array {
  $entries = [
    [
      'version' => (string) $document->get('field_version')->value,
      'date' => _tannwald_date($document->get('field_freigegeben_am')->value),
      'note' => $document->hasField('field_aenderungsvermerk') ? ($document->get('field_aenderungsvermerk')->value ?: NULL) : NULL,
      'url' => NULL,
      'current' => TRUE,
    ],
  ];
  if (!function_exists('_tannwald_document_versions')) {
    return $entries;
  }
  foreach (_tannwald_document_versions($document, $langcode)['older'] as $revision) {
    $url = Url::fromRoute('entity.node.revision', [
      'node' => $document->id(),
      'node_revision' => $revision->getRevisionId(),
    ]);
    $entries[] = [
      'version' => (string) $revision->get('field_version')->value,
      'date' => _tannwald_date($revision->get('field_freigegeben_am')->value),
      'note' => $revision->hasField('field_aenderungsvermerk') ? ($revision->get('field_aenderungsvermerk')->value ?: NULL) : NULL,
      'url' => $url->access() ? $url->toString() : NULL,
      'current' => FALSE,
    ];
  }
  return $entries;
}
