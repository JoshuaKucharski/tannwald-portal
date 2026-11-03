<?php

/**
 * @file
 * Redaktion (5.3, Wireframe 03).
 *
 * Navigation mit Zählern, Review-Karten, Tabelle „Unbeantwortete Fragen“,
 * Layout der /redaktion- und /compliance-Seiten.
 */

declare(strict_types=1);

use Drupal\Component\Utility\Html;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Plugin\views\area\ReviewCounts;

/**
 * Rollen, die den Redaktionsbereich sehen (Pflichtenheft-Namen, D5).
 *
 * @return string[]
 *   Rollen-IDs.
 */
function _tannwald_redaktion_roles(): array {
  return ['redaktion', 'compliance', 'portal_admin'];
}

/**
 * Darf das Konto den Redaktionsbereich sehen?
 */
function _tannwald_redaktion_visible(AccountInterface $account): bool {
  if ((int) $account->id() === 1) {
    return TRUE;
  }
  return (bool) array_intersect(_tannwald_redaktion_roles(), $account->getRoles(TRUE));
}

/**
 * Liegt der Pfad im Redaktionsbereich (/redaktion/*, /compliance/*)?
 */
function _tannwald_redaktion_is_path(string $path): bool {
  return str_starts_with($path, '/redaktion') || str_starts_with($path, '/compliance');
}

/**
 * Aktueller Pfad ohne Sprachpräfix (Routen, keine Aliase).
 */
function _tannwald_redaktion_current_path(): string {
  return (string) \Drupal::service('path.current')->getPath();
}

/**
 * Einträge der Redaktions-Navigation (Vertrag 5.3 c), nur mit Zugriff.
 *
 * @return array<int, array{label: mixed, url: string, count: ?int, active: bool}>
 *   Items für tannwald:redaktion-nav.
 */
function _tannwald_redaktion_nav_items(): array {
  $current = _tannwald_redaktion_current_path();
  $counts = _tannwald_redaktion_counts();
  $candidates = [
    [
      'label' => t('Approvals'),
      'url' => Url::fromUserInput('/redaktion/freigaben'),
      'count' => $counts['freigaben'],
      'match' => '/redaktion/freigaben',
    ],
    [
      'label' => t('Documents'),
      'url' => Url::fromRoute('system.admin_content', [], ['query' => ['type' => 'dokument']]),
      'count' => $counts['dokumente'],
      'match' => '/admin/content',
    ],
    [
      'label' => t('Bulletins'),
      'url' => Url::fromUserInput('/bulletins'),
      'count' => $counts['bulletins'],
      'match' => '/bulletins',
    ],
    [
      'label' => t('Unanswered questions'),
      'url' => Url::fromUserInput('/redaktion/unbeantwortet'),
      'count' => $counts['unbeantwortet'],
      'match' => '/redaktion/unbeantwortet',
    ],
    [
      'label' => t('Usage and costs'),
      // 1.3: Portal-Theme (Anzeige page_portal der Views admin_nutzung und
      // admin_kosten); /admin/tannwald/* bleibt für Claro und D's Tests.
      'url' => Url::fromUserInput('/redaktion/nutzung'),
      'count' => NULL,
      'match' => '/redaktion/nutzung',
    ],
    [
      'label' => t('Export rules'),
      'url' => Url::fromUserInput('/compliance/exportregeln'),
      'count' => NULL,
      'match' => '/compliance/exportregeln',
    ],
    [
      'label' => t('Audit log'),
      'url' => Url::fromUserInput('/compliance/audit'),
      'count' => NULL,
      'match' => '/compliance/audit',
    ],
  ];
  $items = [];
  foreach ($candidates as $candidate) {
    try {
      if (!$candidate['url']->access()) {
        continue;
      }
      $items[] = [
        'label' => $candidate['label'],
        'url' => $candidate['url']->toString(),
        'count' => $candidate['count'],
        'active' => str_starts_with($current, $candidate['match'])
        || ($candidate['match'] === '/redaktion/nutzung' && str_starts_with($current, '/redaktion/kosten')),
      ];
    }
    catch (\Exception) {
      continue;
    }
  }
  return $items;
}

/**
 * Zählungen der Navigation: kleine Count-Queries, keine Views.
 *
 * @return array{freigaben: int, dokumente: int, bulletins: int, unbeantwortet: int}
 *   Zähler.
 */
function _tannwald_redaktion_counts(): array {
  $database = \Drupal::database();
  $freigaben = 0;
  if (class_exists(ReviewCounts::class)) {
    $freigaben = ReviewCounts::counts($database)['zur_pruefung'] ?? 0;
  }
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $dokumente = (int) $storage->getQuery()->accessCheck(FALSE)->condition('type', 'dokument')->count()->execute();
  $bulletins = (int) $storage->getQuery()->accessCheck(FALSE)->condition('type', 'bulletin')->count()->execute();

  // Unbeantwortet: laufender Monat (Zeitzone der Site), abgelehnt oder Daumen
  // runter, je Frage einmal (frage_normalisiert) — wie E's View, nur gezählt.
  $unbeantwortet = 0;
  if ($database->schema()->tableExists('tannwald_assistant_log')) {
    $timezone = new \DateTimeZone(date_default_timezone_get());
    $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00', $timezone))->getTimestamp();
    $query = $database->select('tannwald_assistant_log', 'l');
    $query->addExpression('COUNT(DISTINCT l.frage_normalisiert)', 'total');
    $query->condition('l.created', $monthStart, '>=');
    $group = $query->orConditionGroup()->condition('l.status', 'abgelehnt')->condition('l.bewertung', -1);
    $query->condition($group);
    $unbeantwortet = (int) $query->execute()->fetchField();
  }
  return [
    'freigaben' => (int) $freigaben,
    'dokumente' => $dokumente,
    'bulletins' => $bulletins,
    'unbeantwortet' => $unbeantwortet,
  ];
}

/**
 * Queue-Stand „Queue: n offen“ aus den drei Queues.
 *
 * Queues: tannwald_embed, tannwald_summarize, tannwald_notify.
 *
 * @return array{open: int, label: mixed, detail: string}
 *   Summe, Label und Aufschlüsselung.
 */
function _tannwald_redaktion_queue_status(): array {
  $parts = [];
  $open = 0;
  foreach (['tannwald_embed', 'tannwald_summarize', 'tannwald_notify'] as $name) {
    $count = (int) \Drupal::queue($name)->numberOfItems();
    $open += $count;
    $parts[] = str_replace('tannwald_', '', $name) . ' ' . $count;
  }
  return [
    'open' => $open,
    'label' => t('Queue: @count open', ['@count' => $open]),
    'detail' => implode(' · ', $parts),
  ];
}

/**
 * Variablen für page--redaktion.html.twig: redaktion_nav (Props der SDC).
 */
function _tannwald_redaktion_page_prepare(array &$variables): void {
  $queue = _tannwald_redaktion_queue_status();
  $variables['redaktion_nav'] = [
    'items' => _tannwald_redaktion_nav_items(),
    'queue_label' => $queue['label'],
    'queue_detail' => $queue['detail'],
  ];
  $route = (string) \Drupal::routeMatch()->getRouteName();
  $variables['redaktion_csv_url'] = $route === 'tannwald_access.audit'
    ? Url::fromRoute('tannwald_access.audit_csv')->toString()
    : NULL;
  $variables['redaktion_cache'] = _tannwald_cache_bubble(
    ['user.roles', 'url.path'],
    ['node_list:zusammenfassung', 'node_list:dokument', 'node_list:bulletin']
  );
}

/**
 * Review-Karten der View redaktion_freigaben (Props je Zeile).
 *
 * Ziel: Komponente tannwald:review-card.
 */
function _tannwald_review_cards(array &$variables): void {
  $variables['cards'] = [];
  $view = $variables['view'];
  foreach ($view->result as $row) {
    $entity = $row->_entity ?? NULL;
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'zusammenfassung') {
      continue;
    }
    $langcode = (string) ($row->node_field_data_langcode ?? $entity->language()->getId());
    if ($entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    $variables['cards'][] = _tannwald_review_card_props($entity);
  }
}

/**
 * Props einer Review-Karte aus D's tannwald_ingest_review_card().
 *
 * Vertrag 5.3 d.
 *
 * @return array
 *   card (Props tannwald:review-card), body_html, form, checks[], word_count,
 *   word_target, language_label, document_type, machine_types, nid.
 */
function _tannwald_review_card_props(NodeInterface $summary): array {
  $data = function_exists('tannwald_ingest_review_card') ? tannwald_ingest_review_card($summary) : [];
  $langcode = $summary->language()->getId();
  $target = (int) (\Drupal::config('tannwald_ingest.settings')->get('summary_max_words') ?: 120);

  // Änderungsvermerk und Sprache aus der zusammengefassten Dokument-Revision.
  $changeNote = NULL;
  $languageMatches = FALSE;
  $documentType = NULL;
  $machineTypes = [];
  $vid = $summary->hasField('field_dokument_revision') ? (int) $summary->get('field_dokument_revision')->value : 0;
  if ($vid > 0) {
    $revision = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($vid);
    if ($revision instanceof NodeInterface) {
      $languageMatches = $revision->hasTranslation($langcode);
      if ($languageMatches) {
        $revision = $revision->getTranslation($langcode);
      }
      if ($revision->hasField('field_aenderungsvermerk') && !$revision->get('field_aenderungsvermerk')->isEmpty()) {
        $changeNote = trim(strip_tags((string) $revision->get('field_aenderungsvermerk')->value));
      }
      // 1.3: Typ und Maschinentypen als Pillen im Kartenkopf.
      if ($revision->hasField('field_dokumenttyp') && $revision->get('field_dokumenttyp')->entity !== NULL) {
        $documentType = (string) $revision->get('field_dokumenttyp')->entity->label();
      }
      if ($revision->hasField('field_maschinentyp')) {
        foreach ($revision->get('field_maschinentyp')->referencedEntities() as $term) {
          $machineTypes[] = (string) $term->label();
        }
      }
    }
  }

  $plain = trim(html_entity_decode(strip_tags((string) ($data['body_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);
  $safety = (bool) ($data['sicherheitskapitel_genannt'] ?? FALSE);

  return [
    'nid' => (int) $summary->id(),
    'card' => [
      'document' => [
        'title' => (string) ($data['dokument']['titel'] ?? $summary->label()),
        'version' => (string) ($data['dokument']['version'] ?? ''),
        'url' => (string) ($data['dokument']['url'] ?? ''),
      ],
      'status' => (string) ($data['status'] ?? 'zur_pruefung'),
      'generated_at' => (string) ($data['generiert_am'] ?? ''),
      'model' => $data['modell'] ?? NULL,
      'tokens' => $data['tokens'] ?? NULL,
      'created_by' => ($data['erstellt_durch'] ?? '') !== '' ? $data['erstellt_durch'] : NULL,
      'change_note' => $changeNote,
      'safety_mentioned' => $safety,
    ],
    'language_label' => _tannwald_language_name($langcode),
    'document_type' => $documentType,
    'machine_types' => $machineTypes,
    'body_html' => $data['body_html'] ?? '',
    'form' => $data['form'] ?? [],
    'word_count' => $words,
    'word_target' => $target,
    'checks' => [
      ['label' => t('Safety-relevant chapters mentioned'), 'ok' => $safety],
      ['label' => t('At most @count words', ['@count' => $target]), 'ok' => $words <= $target],
      [
        'label' => t('Language of the document (@language)', ['@language' => _tannwald_language_name($langcode)]),
        'ok' => $languageMatches,
      ],
    ],
  ];
}

/**
 * Zeilen der Tabelle „Unbeantwortete Fragen“: Buttons je Zeile (Wireframe 03).
 */
function _tannwald_unanswered_rows(array &$variables): void {
  $view = $variables['view'];
  $canCreate = \Drupal::currentUser()->hasPermission('create dokument content');
  $supportUrl = Url::fromUserInput('/redaktion/support-anfragen')->toString();
  foreach ($variables['rows'] as $index => &$row) {
    $result = $view->result[$index] ?? NULL;
    $question = '';
    if ($result !== NULL) {
      $question = (string) ($result->tannwald_assistant_log_frage_normalisiert ?? $result->frage_normalisiert ?? '');
    }
    $row['question'] = $question;
    $row['url_create'] = $canCreate
      ? Url::fromRoute('node.add', ['node_type' => 'dokument'], ['query' => ['title' => Html::decodeEntities($question)]])->toString()
      : NULL;
    $row['url_support'] = $supportUrl;
  }
  unset($row);
}

/**
 * Zusammenfassungs-HTML als Text für die Textarea.
 *
 * Absätze durch Leerzeilen, Listenpunkte als „– “-Zeilen, übrige Tags entfernt.
 */
function _tannwald_review_html_to_text(string $html): string {
  if (!str_contains($html, '<')) {
    return trim($html);
  }
  $text = preg_replace('/\s*<li[^>]*>\s*/iu', "\n– ", $html) ?? $html;
  $text = preg_replace('/<\/(p|ul|ol|li)>\s*/iu', "\n\n", $text) ?? $text;
  $text = preg_replace('/<br\s*\/?>/iu', "\n", $text) ?? $text;
  $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
  $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
  return trim($text);
}

/**
 * Lese-Quote je Bulletin für die Redaktion (1.3, Wireframe 03 „Bulletins“).
 *
 * Ergebnis quotas[] parallel zu rows: {confirmed, total, percent, url} oder
 * NULL
 * (Kunden sehen keine Quote; Bulletins ohne Bestätigungspflicht auch nicht).
 * Fällig = offene Paare Nutzer × Maschine aus C's ConfirmationService.
 */
function _tannwald_bulletin_quotas(array &$variables): void {
  $variables['quotas'] = [];
  $view = $variables['view'];
  if (!_tannwald_redaktion_visible(\Drupal::currentUser()) || !\Drupal::hasService('tannwald_bulletin.confirmations')) {
    return;
  }
  /** @var \Drupal\tannwald_bulletin\ConfirmationService $confirmations */
  $confirmations = \Drupal::service('tannwald_bulletin.confirmations');
  $storage = \Drupal::entityTypeManager()->getStorage('lesebestaetigung');
  foreach ($view->result as $index => $row) {
    $bulletin = $row->_entity ?? NULL;
    $variables['quotas'][$index] = NULL;
    if (!$bulletin instanceof NodeInterface || $bulletin->bundle() !== 'bulletin') {
      continue;
    }
    if ($bulletin->hasField('field_bestaetigung_pflicht') && !(bool) $bulletin->get('field_bestaetigung_pflicht')->value) {
      continue;
    }
    $confirmed = (int) $storage->getQuery()->accessCheck(FALSE)->condition('bulletin_nid', $bulletin->id())->count()->execute();
    $open = 0;
    foreach ($confirmations->openConfirmations($bulletin) as $recipient) {
      $open += count($recipient['maschinen']);
    }
    $total = $confirmed + $open;
    $variables['quotas'][$index] = [
      'confirmed' => $confirmed,
      'total' => $total,
      'percent' => $total > 0 ? (int) round($confirmed / $total * 100) : 0,
      'url' => Url::fromUserInput('/redaktion/bulletins/' . $bulletin->id() . '/bestaetigungen')->toString(),
    ];
  }
}
