<?php

/**
 * @file
 * Assistent (5.3, 1.3): Props der SDC tannwald:chat aus Konfig und Limit.
 */

declare(strict_types=1);

use Drupal\Core\Url;

/**
 * Props für tannwald:chat (Vertrag 5.3 a, ergänzt 1.3 um examples und panel).
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
 *   language_names, mode, examples. Leer ([]), wenn die Seite den Assistenten
 *   selbst rendert: seit 1.3 zeigt das Such-Template den Assistenten als
 *   Panel; der Sheet-Aufruf aus tannwald_preprocess() (page, Hunk 5.3) für
 *   dieselbe Route liefert darum nichts mehr ({% if chat %} bleibt falsch).
 */
function _tannwald_chat_props(?int $maschineNid, ?string $machineLabel, string $mode = 'embedded'): array {
  if ($mode === 'sheet' && \Drupal::routeMatch()->getRouteName() === 'tannwald_assistant.search') {
    return [];
  }
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
  $names = [];
  foreach (\Drupal::languageManager()->getLanguages() as $code => $language) {
    $names[$code] = _tannwald_language_name($code);
  }
  return [
    'maschine_nid' => $maschineNid,
    'machine_label' => $machineLabel,
    'model_label' => (string) ($settings->get('chat_model') ?: ''),
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
function _tannwald_chat_cache_bubble(): array {
  return _tannwald_cache_bubble(
    ['user', 'languages:language_interface'],
    ['tannwald_assistant_limit', 'config:tannwald_assistant.settings']
  );
}
