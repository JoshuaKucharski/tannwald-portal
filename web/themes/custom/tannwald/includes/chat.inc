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
    'examples' => _tannwald_chat_example_questions(),
  ];
}

/**
 * Drei Beispielfragen für den Leer-Zustand (1.3).
 *
 * Suche ohne Treffer, Suche ohne Begriff und der Assistent zeigen dieselben.
 *
 * @return string[]
 *   Fragen in der Oberflächensprache.
 */
function _tannwald_chat_example_questions(): array {
  return [
    (string) t('How often is the sealing station cleaned?'),
    (string) t('What does fault code E-4711 mean?'),
    (string) t('Which film thickness is approved?'),
  ];
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
