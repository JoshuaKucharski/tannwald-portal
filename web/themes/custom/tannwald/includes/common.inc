<?php

/**
 * @file
 * Gemeinsame Hilfen des Themes: Datum, Sprachen, Listenwerte, Cache-Element.
 */

declare(strict_types=1);

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Datum TT.MM.JJJJ (Format tannwald_datum) aus einem Date-Only-Wert.
 */
function _tannwald_date(?string $value): ?string {
  if ($value === NULL || $value === '') {
    return NULL;
  }
  try {
    $date = new DrupalDateTime($value, 'UTC');
  }
  catch (\Exception) {
    return NULL;
  }
  return \Drupal::service('date.formatter')->format($date->getTimestamp(), 'tannwald_datum', '', 'UTC');
}

/**
 * Datum TT.MM.JJJJ (Format tannwald_datum) aus einem Unix-Zeitstempel.
 */
function _tannwald_date_from_timestamp(?int $timestamp): ?string {
  if ($timestamp === NULL) {
    return NULL;
  }
  return \Drupal::service('date.formatter')->format($timestamp, 'tannwald_datum');
}

/**
 * Label eines Listenwerts (allowed_values) eines Feldes, sonst der Rohwert.
 */
function _tannwald_list_label(FieldableEntityInterface $entity, string $field): ?string {
  if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
    return NULL;
  }
  $value = (string) $entity->get($field)->value;
  $allowed = $entity->get($field)->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
  return isset($allowed[$value]) ? (string) $allowed[$value] : $value;
}

/**
 * Name einer Sprache in der Oberflächensprache (Deutsch, Englisch …).
 */
function _tannwald_language_name(string $langcode): string {
  $context = ['context' => 'tannwald language'];
  $names = ['de' => t('German', [], $context), 'en' => t('English', [], $context), 'fr' => t('French', [], $context)];
  if (isset($names[$langcode])) {
    return (string) $names[$langcode];
  }
  $language = \Drupal::languageManager()->getLanguage($langcode);
  return $language ? $language->getName() : strtoupper($langcode);
}

/**
 * Sprach-Pillen eines Nodes je Site-Sprache.
 *
 * Je Sprache {code, label, url, current, available}; verfügbar ist eine
 * Sprache, wenn die Übersetzung existiert und veröffentlicht ist.
 */
function _tannwald_language_pills(NodeInterface $node, string $currentLangcode): array {
  $pills = [];
  foreach (\Drupal::languageManager()->getLanguages() as $code => $language) {
    $available = $node->hasTranslation($code) && $node->getTranslation($code)->isPublished();
    $url = NULL;
    if ($available) {
      try {
        $url = $node->getTranslation($code)->toUrl()->toString();
      }
      catch (\Exception) {
        $url = NULL;
      }
    }
    $pills[] = [
      'code' => $code,
      'label' => _tannwald_language_name($code),
      'url' => $url,
      'current' => $code === $currentLangcode,
      'available' => $available,
    ];
  }
  return $pills;
}

/**
 * Leeres Render-Element, das Cache-Kontexte und -Tags in die Seite trägt.
 *
 * Die Templates drucken es als {{ cache_bubble }}; so sieht kunde-b nie eine
 * für kunde-a gecachte Liste (Kontext user.node_grants:view).
 */
function _tannwald_cache_bubble(array $contexts, array $tags): array {
  return [
    '#markup' => '',
    '#cache' => [
      'contexts' => array_values(array_unique($contexts)),
      'tags' => array_values(array_unique($tags)),
    ],
  ];
}

/**
 * URL der Download-Route von 2.4 für ein Dokument mit Datei, sonst NULL.
 */
function _tannwald_download_url(NodeInterface $document): ?string {
  if (!$document->hasField('field_datei') || $document->get('field_datei')->isEmpty()) {
    return NULL;
  }
  try {
    return Url::fromRoute('tannwald_access.download', ['node' => $document->id()])->toString();
  }
  catch (\Exception) {
    return NULL;
  }
}

/**
 * URL der Änderungshistorie (Core-Revisionen), nur mit Zugriff; sonst NULL.
 */
function _tannwald_history_url(NodeInterface $document): ?string {
  try {
    $url = Url::fromRoute('entity.node.version_history', ['node' => $document->id()]);
    return $url->access() ? $url->toString() : NULL;
  }
  catch (\Exception) {
    return NULL;
  }
}

/**
 * Firma (Term-Label) einer Maschine, sonst NULL.
 */
function _tannwald_machine_company(?NodeInterface $machine): ?string {
  if ($machine === NULL || !$machine->hasField('field_firma') || $machine->get('field_firma')->isEmpty()) {
    return NULL;
  }
  $term = $machine->get('field_firma')->entity;
  return $term ? (string) $term->label() : NULL;
}
