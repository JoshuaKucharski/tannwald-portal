<?php

/**
 * @file
 * Bild-Helfer des Themes (Aufgabe 2.2, C): field_bild → Render-Array.
 *
 * Vertrag an A (Preprocess): A ruft tannwald_image() selbst und legt das
 * Ergebnis in machine.image, cards[].image, documents[].image (Thumb) ab.
 * Bildstile: tw_hero_1600 (16:9), tw_card_800 und tw_thumb_320 (3:2), alle
 * WebP. srcset kombiniert nur Stile mit gleichem Seitenverhältnis (3:2:
 * 320w und 800w); ein Hero-Bild hat einen Kandidaten (1600w), weil ein
 * 3:2-Stil im srcset den Bildausschnitt je Breite wechseln würde.
 * Alt-Text: field_bild ist nicht übersetzbar und trägt den deutschen Text;
 * en/fr kommen aus fixtures/bilder/manifest.json über tannwald_demo.lookup.
 */

declare(strict_types=1);

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Bildstile: Name => [width, height, Gruppe (Seitenverhältnis), sizes].
 *
 * @return array<string, array{0: int, 1: int, 2: string, 3: string}>
 *   Die drei Stile aus config/sync (image.style.tw_*).
 */
function tannwald_image_styles(): array {
  return [
    'tw_hero_1600' => [1600, 900, '16x9', '100vw'],
    'tw_card_800' => [800, 533, '3x2', '(min-width: 64rem) 25rem, 100vw'],
    'tw_thumb_320' => [320, 213, '3x2', '10rem'],
  ];
}

/**
 * Render-Array für das Bild einer Entity in einem Bildstil.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   Term maschinentyp/dokumenttyp oder Node maschine/dokument/bulletin.
 * @param string $style
 *   tw_hero_1600, tw_card_800 (Standard) oder tw_thumb_320.
 * @param array $options
 *   - loading: 'lazy' (Standard) oder 'eager' (Hero, erste Karte); bei
 *     tw_hero_1600 ist 'eager' Standard.
 *   - sizes: überschreibt den sizes-Wert des Stils.
 *   - alt: überschreibt den Alt-Text.
 *   - fallback: Entity, deren Bild gilt, wenn $entity keines hat (z. B. der
 *     Maschinentyp einer Maschine, der Dokumenttyp eines Dokuments).
 *   - class: zusätzliche Klassen (string[]).
 *   - placeholder: FALSE liefert [] statt des Platzhalters.
 *
 * @return array
 *   #theme image_style mit srcset/sizes/loading, oder der Platzhalter
 *   (div.tw-image.tw-image--placeholder.tw-image--<ratio>, aria-hidden),
 *   jeweils mit Cache-Metadaten (Entity, Datei, Stil, Oberflächensprache).
 */
function tannwald_image(EntityInterface $entity, string $style = 'tw_card_800', array $options = []): array {
  if (!isset(tannwald_image_styles()[$style])) {
    $style = 'tw_card_800';
  }
  [, , $ratio, $sizes] = tannwald_image_styles()[$style];
  $cache = CacheableMetadata::createFromObject($entity)->addCacheContexts(['languages:language_interface']);
  $classes = array_merge(['tw-image', 'tw-image--' . $ratio], (array) ($options['class'] ?? []));

  [$item, $file] = _tannwald_image_item($entity);
  if ($file === NULL && ($options['fallback'] ?? NULL) instanceof EntityInterface) {
    $cache->addCacheableDependency($options['fallback']);
    [$item, $file] = _tannwald_image_item($options['fallback']);
  }
  if ($file === NULL) {
    if (($options['placeholder'] ?? TRUE) === FALSE) {
      $build = [];
      $cache->applyTo($build);
      return $build;
    }
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'class' => array_merge($classes, ['tw-image--placeholder']),
        'aria-hidden' => 'true',
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }

  $cache->addCacheableDependency($file);
  $imageStyle = ImageStyle::load($style);
  if ($imageStyle !== NULL) {
    $cache->addCacheableDependency($imageStyle);
  }
  $uri = $file->getFileUri();
  $srcset = _tannwald_image_srcset($uri, $ratio);
  $attributes = [
    'class' => $classes,
    'loading' => $options['loading'] ?? ($style === 'tw_hero_1600' ? 'eager' : 'lazy'),
    'decoding' => 'async',
  ];
  if (count($srcset) > 1) {
    $attributes['srcset'] = implode(', ', $srcset);
    $attributes['sizes'] = $options['sizes'] ?? $sizes;
  }
  $build = [
    '#theme' => 'image_style',
    '#style_name' => $style,
    '#uri' => $uri,
    '#width' => $item['width'] ?? NULL,
    '#height' => $item['height'] ?? NULL,
    '#alt' => $options['alt'] ?? _tannwald_image_alt($file, (string) ($item['alt'] ?? '')),
    '#attributes' => $attributes,
  ];
  $cache->applyTo($build);
  return $build;
}

/**
 * URL des Bildes einer Entity in einem Bildstil (relativ zur Site) oder NULL.
 */
function tannwald_image_url(EntityInterface $entity, string $style): ?string {
  [, $file] = _tannwald_image_item($entity);
  $imageStyle = ImageStyle::load($style);
  if ($file === NULL || $imageStyle === NULL) {
    return NULL;
  }
  return \Drupal::service('file_url_generator')->transformRelative($imageStyle->buildUrl($file->getFileUri()));
}

/**
 * Feldwerte und Datei von field_bild.
 *
 * @return array{0: array<string, mixed>, 1: \Drupal\file\FileInterface|null}
 *   Werte des Feldelements (target_id, alt, width, height) und Datei.
 */
function _tannwald_image_item(EntityInterface $entity): array {
  if (!$entity instanceof FieldableEntityInterface || !$entity->hasField('field_bild')) {
    return [[], NULL];
  }
  // Bildfeld an Maschinentyp, Dokumenttyp, Maschine, Dokument und Bulletin.
  $item = $entity->get('field_bild')->first();
  $file = $item?->get('entity')->getValue();
  return $file instanceof FileInterface ? [$item->getValue(), $file] : [[], NULL];
}

/**
 * Kandidaten "url breite w" aller Stile mit gleichem Seitenverhältnis.
 *
 * @return string[]
 *   srcset-Einträge, schmalste zuerst.
 */
function _tannwald_image_srcset(string $uri, string $ratio): array {
  $generator = \Drupal::service('file_url_generator');
  $candidates = [];
  foreach (tannwald_image_styles() as $name => [$width, , $group]) {
    $imageStyle = $group === $ratio ? ImageStyle::load($name) : NULL;
    if ($imageStyle !== NULL) {
      $candidates[$width] = $generator->transformRelative($imageStyle->buildUrl($uri)) . ' ' . $width . 'w';
    }
  }
  ksort($candidates);
  return array_values($candidates);
}

/**
 * Alt-Text in der Oberflächensprache: Manifest (en/fr), sonst das Feld (de).
 */
function _tannwald_image_alt(FileInterface $file, string $fieldAlt): string {
  $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
  if ($langcode !== 'de' && \Drupal::hasService('tannwald_demo.lookup')) {
    $translated = \Drupal::service('tannwald_demo.lookup')->imageAlt($file->getFilename(), $langcode);
    if (is_string($translated) && $translated !== '') {
      return $translated;
    }
  }
  return $fieldAlt;
}
