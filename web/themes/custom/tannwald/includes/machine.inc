<?php

/**
 * @file
 * Maschinenseite (Wireframe 01) und Karten der Liste „Meine Maschinen“.
 */

declare(strict_types=1);

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;

/**
 * Reihenfolge der Dokumenttypen (field_slug) auf der Maschinenseite.
 *
 * Wie Wireframe 01; typweite Zertifikate und Schulungsunterlagen am Ende
 * (Entscheid des Masters).
 *
 * @return string[]
 *   Slugs in Anzeigereihenfolge.
 */
function _tannwald_doctype_order(): array {
  return [
    'betriebsanleitung',
    'ersatzteilkatalog',
    'schaltplan',
    'wartungsplan',
    'anlagenspezifisch',
    'zertifikat',
    'schulungsunterlage',
  ];
}

/**
 * Variablen für node--maschine.html.twig.
 */
function _tannwald_machine_preprocess(array &$variables, NodeInterface $machine): void {
  $variables += _tannwald_machine_data($machine, \Drupal::currentUser());
}

/**
 * Alle Daten der Maschinenseite (auch für die Karten der Liste).
 *
 * @return array<string, mixed>
 *   machine, documents, documents_current, documents_new, documents_older,
 *   language_label, fallback_label, banners, bulletins, bulletins_open,
 *   bulletins_confirmed, maschine_nid, cache_bubble.
 */
function _tannwald_machine_data(NodeInterface $machine, AccountInterface $account): array {
  $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
  $typeIds = [];
  $typeName = NULL;
  $typeNote = NULL;
  if ($machine->hasField('field_maschinentyp')) {
    foreach ($machine->get('field_maschinentyp')->referencedEntities() as $term) {
      $typeIds[] = (int) $term->id();
      $typeName ??= (string) $term->label();
      $description = $term->hasField('description') ? trim(strip_tags((string) $term->get('description')->value)) : '';
      $typeNote ??= $description !== '' ? $description : NULL;
    }
  }

  $typeTerm = $machine->hasField('field_maschinentyp') ? $machine->get('field_maschinentyp')->entity : NULL;
  $typeTerm = $typeTerm instanceof TermInterface ? $typeTerm : NULL;
  $hasOwnImage = $machine->hasField('field_bild') && !$machine->get('field_bild')->isEmpty();
  $documents = _tannwald_machine_documents($machine, $typeIds, $account, $langcode);
  $bulletins = _tannwald_machine_bulletins($machine, $typeIds, $account);

  $contexts = ['user', 'user.node_grants:view', 'languages:language_interface'];
  $tags = array_merge(
    ['node_list:dokument', 'node_list:bulletin', 'lesebestaetigung_list', 'node:' . $machine->id()],
    $documents['tags'],
    $bulletins['tags'],
  );

  return [
    'machine' => [
      'serial' => (string) $machine->label(),
      'type_name' => $typeName,
      'type_note' => $typeNote,
      'company' => _tannwald_machine_company($machine),
      'location' => $machine->hasField('field_standort') ? $machine->get('field_standort')->value : NULL,
      'year' => $machine->hasField('field_baujahr') ? $machine->get('field_baujahr')->value : NULL,
      'contract_label' => _tannwald_list_label($machine, 'field_vertrag'),
      'contract_until' => $machine->hasField('field_vertrag_bis') ? _tannwald_date($machine->get('field_vertrag_bis')->value) : NULL,
      // 1.2: Seriennummernbild, sonst Typbild, sonst Platzhalter (Kette
      // Node → Typ → Platzhalter im Helfer von C, Option fallback).
      'image' => _tannwald_entity_image($machine, 'tw_card_800', $typeTerm, ['loading' => 'eager']),
      'has_own_image' => $hasOwnImage,
    ],
    'documents' => $documents['rows'],
    'documents_current' => $documents['current'],
    'documents_new' => $documents['new'],
    'documents_older' => $documents['older'],
    'language_label' => _tannwald_language_name($langcode),
    'fallback_label' => $documents['fallback'],
    'banners' => $bulletins['banners'],
    'bulletins' => $bulletins['items'],
    'bulletins_open' => $bulletins['open'],
    'bulletins_confirmed' => $bulletins['confirmed'],
    'maschine_nid' => (int) $machine->id(),
    'cache_bubble' => _tannwald_cache_bubble($contexts, $tags),
  ];
}

/**
 * Dokumentliste der Maschine: Entity Query mit Access-Check (Grants von 2.4).
 *
 * Typweite Dokumente (field_maschinentyp trifft, kein field_seriennummer) oder
 * anlagenspezifische (field_seriennummer = Maschine), veröffentlicht, sortiert
 * nach _tannwald_doctype_order() und Titel. Ältere veröffentlichte Revisionen
 * folgen als Zeilen mit is_current = false.
 *
 * @return array{rows: array, current: int, new: int, older: int, fallback: ?string, tags: string[]}
 *   Zeilen (Props für tannwald:doc-row) und Zähler.
 */
function _tannwald_machine_documents(NodeInterface $machine, array $typeIds, AccountInterface $account, string $langcode): array {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $repository = \Drupal::service('entity.repository');
  $query = $storage->getQuery()
    ->accessCheck(TRUE)
    ->condition('type', 'dokument')
    ->condition('status', 1);
  $scope = $query->orConditionGroup()
    ->condition('field_seriennummer', $machine->id());
  if ($typeIds !== []) {
    $scope->condition($query->andConditionGroup()
      ->condition('field_maschinentyp', $typeIds, 'IN')
      ->notExists('field_seriennummer'));
  }
  $nids = $query->condition($scope)->execute();

  $entries = [];
  foreach ($storage->loadMultiple($nids) as $node) {
    /** @var \Drupal\node\NodeInterface $translation */
    $translation = $repository->getTranslationFromContext($node, $langcode);
    $slug = '';
    $term = $node->hasField('field_dokumenttyp') ? $node->get('field_dokumenttyp')->entity : NULL;
    if ($term instanceof TermInterface && $term->hasField('field_slug')) {
      $slug = (string) $term->get('field_slug')->value;
    }
    $order = array_search($slug, _tannwald_doctype_order(), TRUE);
    $entries[] = [
      'order' => $order === FALSE ? count(_tannwald_doctype_order()) : $order,
      'node' => $node,
      'translation' => $translation,
    ];
  }
  usort($entries, static function (array $a, array $b): int {
    return [$a['order'], $a['translation']->label()] <=> [$b['order'], $b['translation']->label()];
  });

  $rows = [];
  $new = 0;
  $older = 0;
  $fallback = NULL;
  $tags = [];
  foreach ($entries as $entry) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $entry['node'];
    /** @var \Drupal\node\NodeInterface $translation */
    $translation = $entry['translation'];
    $tags = array_merge($tags, $node->getCacheTags());
    if ($fallback === NULL && $translation->language()->getId() !== $langcode) {
      $fallback = _tannwald_language_name($translation->language()->getId());
    }
    $versions = _tannwald_document_versions($node, $langcode);
    $isNew = $versions['older'] !== [] && $versions['current_is_latest'];
    $new += $isNew ? 1 : 0;
    $typeTerm = $node->hasField('field_dokumenttyp') ? $node->get('field_dokumenttyp')->entity : NULL;
    $typeTerm = $typeTerm instanceof TermInterface ? $typeTerm : NULL;
    $extra = [
      'type_label' => $typeTerm ? (string) $typeTerm->label() : '',
      'type_slug' => $typeTerm && $typeTerm->hasField('field_slug') ? (string) $typeTerm->get('field_slug')->value : '',
      // 1.2: Thumb 320 — Dokumentbild, sonst Titelbild des Dokumenttyps.
      'image' => _tannwald_entity_image($node, 'tw_thumb_320', $typeTerm),
    ];
    $rows[] = _tannwald_document_row($translation, $langcode, TRUE, $isNew) + $extra;
    foreach ($versions['older'] as $revision) {
      $older++;
      $rows[] = _tannwald_document_row($revision, $langcode, FALSE, FALSE) + $extra + ['image' => []];
    }
  }

  return [
    'rows' => $rows,
    'current' => count($entries),
    'new' => $new,
    'older' => $older,
    'fallback' => $fallback,
    'tags' => $tags,
  ];
}

/**
 * Ältere veröffentlichte Versionen eines Dokuments.
 *
 * Je field_version zählt die jüngste Revision.
 *
 * @return array{older: \Drupal\node\NodeInterface[], current_is_latest: bool}
 *   Ältere Revisionen (neueste zuerst) und ob die aktuelle Revision die
 *   jüngste Freigabe trägt (Badge „Neue Version“).
 */
function _tannwald_document_versions(NodeInterface $node, string $langcode): array {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $vids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->allRevisions()
    ->condition('nid', $node->id())
    ->condition('status', 1)
    ->sort('vid', 'DESC')
    ->execute();
  $currentVersion = (string) $node->get('field_version')->value;
  $currentDate = (string) $node->get('field_freigegeben_am')->value;
  $older = [];
  $seen = [$currentVersion => TRUE];
  $latest = TRUE;
  foreach (array_keys($vids) as $vid) {
    if ((int) $vid === (int) $node->getRevisionId()) {
      continue;
    }
    /** @var \Drupal\node\NodeInterface|null $revision */
    $revision = $storage->loadRevision($vid);
    if ($revision === NULL) {
      continue;
    }
    if ($revision->hasTranslation($langcode)) {
      $revision = $revision->getTranslation($langcode);
    }
    $version = (string) $revision->get('field_version')->value;
    if ($version === '' || isset($seen[$version]) || !$revision->isPublished()) {
      continue;
    }
    $seen[$version] = TRUE;
    $older[] = $revision;
    if ((string) $revision->get('field_freigegeben_am')->value > $currentDate) {
      $latest = FALSE;
    }
  }
  return ['older' => $older, 'current_is_latest' => $latest];
}

/**
 * Props einer Zeile für tannwald:doc-row (Vertrag (b)).
 */
function _tannwald_document_row(NodeInterface $document, string $langcode, bool $isCurrent, bool $isNew): array {
  $export = $document->hasField('field_exportklasse') ? (string) $document->get('field_exportklasse')->value : 'keine';
  $badges = [];
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
      $badges[] = [
        'label' => t('Only @company', ['@company' => $company]),
        'variant' => 'private',
      ];
    }
  }

  if ($isCurrent) {
    // 6.1 (E): Präfix der Oberflächensprache; ein Klick wechselt sie nicht.
    $url = $document->toUrl('canonical', ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString();
    $download = _tannwald_download_url($document);
  }
  else {
    $revisionUrl = Url::fromRoute('entity.node.revision', [
      'node' => $document->id(),
      'node_revision' => $document->getRevisionId(),
    ]);
    $url = $revisionUrl->access() ? $revisionUrl->toString() : NULL;
    $download = NULL;
  }

  return [
    'title' => (string) $document->label(),
    'url_dokument' => $url,
    'url_download' => $download,
    'version' => (string) $document->get('field_version')->value,
    'freigegeben_am' => _tannwald_date($document->get('field_freigegeben_am')->value),
    'url_history' => $isCurrent ? _tannwald_history_url($document) : NULL,
    'languages' => $isCurrent ? _tannwald_language_pills($document, $document->language()->getId()) : [],
    'badges' => $badges,
    'is_new' => $isNew,
    'is_current' => $isCurrent,
    'audit_hint' => $export !== '' && $export !== 'keine',
  ];
}

/**
 * Bulletins der Maschine mit Status Offen / Bestätigt und Banner-Kandidat.
 *
 * @return array{items: array, open: int, confirmed: int, banners: array, tags: string[]}
 *   Liste, Zähler, Banner (offene Bulletins mit Formular, jüngstes zuerst).
 */
function _tannwald_machine_bulletins(NodeInterface $machine, array $typeIds, AccountInterface $account): array {
  $empty = ['items' => [], 'open' => 0, 'confirmed' => 0, 'banners' => [], 'tags' => []];
  if (!function_exists('tannwald_bulletin_banner_data')) {
    return $empty;
  }
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $query = $storage->getQuery()
    ->accessCheck(TRUE)
    ->condition('type', 'bulletin')
    ->condition('status', 1)
    ->sort('created', 'DESC');
  $scope = $query->orConditionGroup()->condition('field_seriennummern', $machine->id());
  if ($typeIds !== []) {
    $scope->condition('field_maschinentyp', $typeIds, 'IN');
  }
  $nids = $query->condition($scope)->execute();

  $items = [];
  $open = 0;
  $confirmed = 0;
  $banners = [];
  $tags = [];
  foreach ($storage->loadMultiple($nids) as $bulletin) {
    $tags = array_merge($tags, $bulletin->getCacheTags());
    $data = tannwald_bulletin_banner_data($bulletin, $machine, $account);
    $data['confirmed_by'] = NULL;
    if (!empty($data['bestaetigt_durch_uid'])) {
      $confirmedBy = User::load($data['bestaetigt_durch_uid']);
      $data['confirmed_by'] = $confirmedBy ? (string) $confirmedBy->getDisplayName() : NULL;
    }
    if ($data['form'] !== NULL || (!empty($data['bestaetigung_pflicht']) && empty($data['bestaetigt_am']))) {
      $status = 'open';
      $open++;
      if ($data['form'] !== NULL) {
        $banners[] = $data;
      }
    }
    elseif (!empty($data['bestaetigt_am'])) {
      $status = 'confirmed';
      $confirmed++;
    }
    else {
      $status = 'none';
    }
    $items[] = [
      'id' => $data['id'],
      'title' => $data['titel'],
      'kind_label' => $data['art'] === 'sicherheit' ? t('Safety bulletin') : t('Service bulletin'),
      'url' => $data['url_bulletin'],
      'published' => _tannwald_date_from_timestamp($data['veroeffentlicht_am']),
      'status' => $status,
      'status_label' => match ($status) {
        'open' => t('Open', [], ['context' => 'tannwald status']),
        'confirmed' => t('Confirmed'),
        default => t('Information'),
      },
      'note' => match ($status) {
        'open' => t('Read confirmation required'),
        'confirmed' => t('confirmed on @date by @name', [
          '@date' => _tannwald_date_from_timestamp($data['bestaetigt_am']),
          '@name' => $data['confirmed_by'] ?? '',
        ]),
        default => t('No confirmation required'),
      },
    ];
  }
  return ['items' => $items, 'open' => $open, 'confirmed' => $confirmed, 'banners' => $banners, 'tags' => $tags];
}

/**
 * Karten der Liste „Meine Maschinen“ (views-view-table--portal-maschinen).
 */
function _tannwald_machine_cards(array &$variables): void {
  $account = \Drupal::currentUser();
  $cards = [];
  $tags = [];
  foreach ($variables['view']->result as $index => $row) {
    $machine = $row->_entity ?? NULL;
    if (!$machine instanceof NodeInterface) {
      continue;
    }
    $data = _tannwald_machine_data($machine, $account);
    $tags = array_merge($tags, $data['cache_bubble']['#cache']['tags']);
    $typeTerm = $machine->hasField('field_maschinentyp') ? $machine->get('field_maschinentyp')->entity : NULL;
    $typeTerm = $typeTerm instanceof TermInterface ? $typeTerm : NULL;
    $cards[] = [
      // 1.2: Bild der Karte (Maschine, sonst Typ), erste Karte eager.
      'image' => _tannwald_entity_image($machine, 'tw_card_800', $typeTerm, [
        'loading' => $index === 0 ? 'eager' : 'lazy',
      ]),
      'serial' => $data['machine']['serial'],
      'url' => $machine->toUrl('canonical', ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString(),
      'type_name' => $data['machine']['type_name'],
      'location' => $data['machine']['location'],
      'year' => $data['machine']['year'],
      'contract_label' => $data['machine']['contract_label'],
      'contract_until' => $data['machine']['contract_until'],
      'documents_current' => $data['documents_current'],
      'documents_new' => $data['documents_new'],
      'bulletins_open' => $data['bulletins_open'],
      'bulletins_confirmed' => $data['bulletins_confirmed'],
    ];
  }
  $variables['cards'] = $cards;
  $variables['cache_bubble'] = _tannwald_cache_bubble(['user', 'user.node_grants:view', 'languages:language_interface'], $tags);
}

/**
 * Bild einer Entity mit Fallback-Kette Node → Typ-Term → Platzhalter (1.2).
 *
 * Ruft C's tannwald_image() (includes/images.inc) mit der Option fallback;
 * ohne Helfer (z. B. Kernel-Test ohne Theme) ein leeres Render-Array.
 */
function _tannwald_entity_image(NodeInterface $node, string $style, ?TermInterface $fallback, array $options = []): array {
  if (!function_exists('tannwald_image')) {
    return [];
  }
  if ($fallback !== NULL) {
    $options['fallback'] = $fallback;
  }
  return tannwald_image($node, $style, $options);
}
