<?php

/**
 * @file
 * Assistent (5.3, 1.3): Props der SDC tannwald:chat aus Konfig und Limit.
 */

declare(strict_types=1);

use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Props für tannwald:chat (Vertrag 5.3 a, ergänzt 1.3 um examples und panel).
 *
 * Nachtrag 3 tw2-11: Modus sheet auf jeder Seite, Kontext aus
 * _tannwald_chat_sheet_context().
 *
 * @param int|null $maschineNid
 *   Maschine der Seite, NULL auf /suche.
 * @param string|null $machineLabel
 *   Seriennummer für die Anzeige.
 * @param string $mode
 *   'embedded' (Aside der Maschinenseite), 'panel' (Spalte auf /suche, 1.3)
 *   oder 'sheet' (nur Launcher und Sheet).
 *
 * @return array
 *   Props: maschine_nid, machine_label, model_label, limit_notice, endpoint,
 *   feedback_endpoint, support_endpoint, token_endpoint, intro, ui_langcode,
 *   language_names, mode, examples.
 */
function _tannwald_chat_props(?int $maschineNid, ?string $machineLabel, string $mode = 'embedded'): array {
  $settings = \Drupal::config('tannwald_assistant.settings');
  $limitNotice = NULL;
  // Abgeschalteter Assistent: derselbe Hinweis, den der Endpunkt als 503
  // liefert.
  if ($settings->get('assistant_enabled') === FALSE) {
    $limitNotice = (string) t('The assistant is currently unavailable. You can still use the search.');
  }
  elseif (\Drupal::hasService('tannwald_assistant.limit_check')) {
    /** @var \Drupal\tannwald_assistant\Limit\LimitService $limit */
    $limit = \Drupal::service('tannwald_assistant.limit_check');
    $limitNotice = $limit->noticeForUi();
  }
  // Nachtrag 2 (c): seit 3.1 steht das Modell unter
  // providers.<provider_chat>.chat_model; der alte Schlüssel chat_model bleibt
  // als Rückfall (Anzeige „Modell: qwen-plus“ statt „—“).
  $providerChat = (string) $settings->get('provider_chat');
  $modelLabel = (string) ($settings->get("providers.$providerChat.chat_model") ?: $settings->get('chat_model') ?: '');
  $names = [];
  foreach (\Drupal::languageManager()->getLanguages() as $code => $language) {
    $names[$code] = _tannwald_language_name($code);
  }
  return [
    'maschine_nid' => $maschineNid,
    'machine_label' => $machineLabel,
    'model_label' => $modelLabel,
    'limit_notice' => $limitNotice,
    'endpoint' => Url::fromUserInput('/api/assistant')->toString(),
    'feedback_endpoint' => Url::fromUserInput('/api/assistant/feedback')->toString(),
    'support_endpoint' => Url::fromUserInput('/api/assistant/support')->toString(),
    'token_endpoint' => Url::fromRoute('system.csrftoken')->toString(),
    'intro' => $maschineNid !== NULL
      ? (string) t('Answers only from approved documents of this machine')
      : (string) t('Answers only from approved documents you are allowed to see'),
    'ui_langcode' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
    'language_names' => $names,
    'mode' => $mode,
    'examples' => _tannwald_chat_example_questions(_tannwald_chat_type_code($maschineNid)),
    // Nachtrag 7 (tw2-11): Zustand und Verlauf im Browser je Konto.
    'uid' => (int) \Drupal::currentUser()->id(),
  ];
}

/**
 * Drei Beispielfragen für den Leer-Zustand (1.3, Nachtrag 9 tw2-11).
 *
 * Suche ohne Treffer, Suche ohne Begriff, Such-Overlay und der Assistent
 * zeigen dieselben. Alle Fragen stammen aus docs/evals/fragen.yaml mit
 * erwartet: antwort — je Maschinentyp drei (TB-200: q-001, q-003, q-015;
 * TB-400: q-061, q-063, q-076; KT-500: q-021, q-022, q-065). Die erste ist
 * zugleich der Platzhalter des Fragefelds.
 *
 * @param string|null $typeCode
 *   Typ-Code (TB200, TB400, KT500); NULL oder unbekannt = erster Typ des
 *   Kontos, siehe _tannwald_chat_type_code().
 *
 * @return string[]
 *   Fragen in der Oberflächensprache.
 */
function _tannwald_chat_example_questions(?string $typeCode = NULL): array {
  $sets = [
    'TB200' => [
      (string) t('How often must the sealing station be cleaned?'),
      (string) t('What does E-4711 mean?'),
      (string) t('Which cleaning agents are approved for the TB-200?'),
    ],
    'TB400' => [
      (string) t('How many cycles per minute does the TB-400 achieve per web?'),
      (string) t('What has to be checked monthly on the TB-400?'),
      (string) t('What must be set after a roll change on one web of the TB-400?'),
    ],
    'KT500' => [
      (string) t('How often must the carton detection cell of the KT-500 be calibrated?'),
      (string) t('Which cleaning agents are approved for the KT-500?'),
      (string) t('Why does the closure of a carton on the KT-500 not hold?'),
    ],
  ];
  $typeCode ??= _tannwald_chat_type_code(NULL);
  return $sets[$typeCode] ?? $sets['TB200'];
}

/**
 * Typ-Code für die Beispielfragen (Nachtrag 9 tw2-11).
 *
 * Maschinenseite: Typ der Maschine (field_maschinentyp → field_typ_code);
 * sonst der erste Typ des Kontos aus den Entitlement-Keys (type:<code>,
 * Reihenfolge wie getRealmsForAccount(); kunde-b hat kein TB-200). Intern
 * (role:intern, keine type-Keys) und anonym: TB-200.
 *
 * @param int|null $maschineNid
 *   Maschine der Seite oder NULL.
 *
 * @return string
 *   TB200, TB400 oder KT500.
 */
function _tannwald_chat_type_code(?int $maschineNid): string {
  if ($maschineNid !== NULL) {
    $machine = \Drupal::entityTypeManager()->getStorage('node')->load($maschineNid);
    if ($machine instanceof NodeInterface && $machine->hasField('field_maschinentyp')) {
      $term = $machine->get('field_maschinentyp')->entity;
      if ($term && $term->hasField('field_typ_code') && ($code = (string) $term->get('field_typ_code')->value) !== '') {
        return $code;
      }
    }
  }
  if (\Drupal::hasService('tannwald_access.entitlement')) {
    foreach (\Drupal::service('tannwald_access.entitlement')->getRealmsForAccount(\Drupal::currentUser()) as $realm) {
      if (preg_match('/^type:([A-Z0-9]+)/', (string) $realm, $match)) {
        return $match[1];
      }
    }
  }
  return 'TB200';
}

/**
 * Cache-Element für Seiten mit Chat: Limit-Tag und Konfiguration.
 */
function _tannwald_chat_cache_bubble(array $contexts = [], array $tags = []): array {
  return _tannwald_cache_bubble(
    array_merge(['user', 'languages:language_interface'], $contexts),
    array_merge(['tannwald_assistant_limit', 'config:tannwald_assistant.settings'], $tags)
  );
}

/**
 * Kontext des globalen Launchers (Nachtrag 3 tw2-11), NULL = kein Launcher.
 *
 * Kein Launcher auf Anmelde- und Konto-Routen (user.*) und auf den
 * Fehlerseiten 403/404 (Nachtrag 6: /suche und Maschinenseite haben keine
 * eingebettete Fassung mehr, der Launcher gilt überall). Auf der Maschinen-
 * und der Dokumentseite bekommt die Frage die Maschine mit, sofern der
 * Nutzer sie sehen darf (Hinweiszeile „Fragt zu: …“ im Kopf).
 *
 * @return array|null
 *   maschine_nid, machine_label und Cache-Tags, oder NULL.
 */
function _tannwald_chat_sheet_context(): ?array {
  $routeMatch = \Drupal::routeMatch();
  $route = (string) $routeMatch->getRouteName();
  if (str_starts_with($route, 'user.') || in_array($route, ['system.403', 'system.404'], TRUE)) {
    return NULL;
  }
  $context = ['maschine_nid' => NULL, 'machine_label' => NULL, 'tags' => []];
  if ($route !== 'entity.node.canonical') {
    return $context;
  }
  $node = $routeMatch->getParameter('node');
  if (!$node instanceof NodeInterface) {
    return $context;
  }
  // Nachtrag 6: die Maschinenseite hat keine eingebettete Spalte mehr; der
  // Launcher trägt die Maschine als Kontext.
  if ($node->bundle() === 'maschine' && $node->access('view')) {
    $context['maschine_nid'] = (int) $node->id();
    $context['machine_label'] = (string) $node->label();
    $context['tags'] = ['node:' . $node->id()];
    return $context;
  }
  if ($node->bundle() === 'dokument' && $node->hasField('field_seriennummer')) {
    $machine = $node->get('field_seriennummer')->entity;
    if ($machine instanceof NodeInterface && $machine->access('view')) {
      $context['maschine_nid'] = (int) $machine->id();
      $context['machine_label'] = (string) $machine->label();
      $context['tags'] = ['node:' . $machine->id()];
    }
  }
  return $context;
}
