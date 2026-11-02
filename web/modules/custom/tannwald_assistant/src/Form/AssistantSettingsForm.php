<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tannwald_assistant\AnswerTexts;
use Drupal\tannwald_assistant\Limit\LimitService;

/**
 * Konfiguration „Assistent“ unter /admin/config/tannwald/assistent (4.2 h).
 */
final class AssistantSettingsForm extends ConfigFormBase {

  public const CONFIG = 'tannwald_assistant.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tannwald_assistant_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG);
    $form['assistant_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Assistant enabled'),
      '#default_value' => (bool) $config->get('assistant_enabled'),
    ];
    $form['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider'),
      '#description' => $this->t('Plugin ID of the AI module, e.g. ollama.'),
      '#default_value' => $config->get('provider'),
      '#required' => TRUE,
    ];
    $form['chat_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Chat model'),
      '#description' => $this->t('Raw model name of the provider, e.g. qwen2.5:7b.'),
      '#default_value' => $config->get('chat_model'),
      '#required' => TRUE,
    ];
    $form['embedding_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embedding model'),
      '#default_value' => $config->get('embedding_model'),
      '#required' => TRUE,
    ];
    $form['top_k'] = [
      '#type' => 'number',
      '#title' => $this->t('Chunks in the prompt (top_k)'),
      '#min' => 1,
      '#max' => 20,
      '#default_value' => $config->get('top_k'),
      '#required' => TRUE,
    ];
    $form['min_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum RRF score (min_score)'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.0001,
      '#default_value' => $config->get('min_score'),
      '#required' => TRUE,
    ];
    $form['monthly_token_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly token limit'),
      '#description' => $this->t('0 = unlimited. Warning mail at 80 %, search only at 100 %.'),
      '#min' => 0,
      '#default_value' => $config->get('monthly_token_limit'),
      '#required' => TRUE,
    ];
    $form['kosten_pro_1k_chf'] = [
      '#type' => 'number',
      '#title' => $this->t('Cost per 1,000 tokens (CHF)'),
      '#min' => 0,
      '#step' => 0.0001,
      '#default_value' => $config->get('kosten_pro_1k_chf'),
      '#required' => TRUE,
    ];
    $form['safety_terms'] = [
      '#type' => 'details',
      '#title' => $this->t('Safety terms'),
      '#description' => $this->t('One term per line; a trailing * also matches longer words.'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];
    foreach (AnswerTexts::LANGCODES as $langcode) {
      $form['safety_terms'][$langcode] = [
        '#type' => 'textarea',
        '#title' => $this->t('Safety terms (@langcode)', ['@langcode' => $langcode]),
        '#rows' => 8,
        '#default_value' => implode("\n", (array) $config->get('safety_terms.' . $langcode)),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['provider', 'chat_model', 'embedding_model'] as $key) {
      if (!preg_match('/^[A-Za-z0-9._:\/-]+$/', (string) $form_state->getValue($key))) {
        $form_state->setErrorByName($key, $this->t('Only letters, digits and . _ : / - are allowed.'));
      }
    }
    foreach (AnswerTexts::LANGCODES as $langcode) {
      if (self::lines((string) $form_state->getValue(['safety_terms', $langcode])) === []) {
        $form_state->setErrorByName('safety_terms][' . $langcode, $this->t('At least one safety term per language is required.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $terms = [];
    foreach (AnswerTexts::LANGCODES as $langcode) {
      $terms[$langcode] = self::lines((string) $form_state->getValue(['safety_terms', $langcode]));
    }
    $this->config(self::CONFIG)
      ->set('assistant_enabled', (bool) $form_state->getValue('assistant_enabled'))
      ->set('provider', trim((string) $form_state->getValue('provider')))
      ->set('chat_model', trim((string) $form_state->getValue('chat_model')))
      ->set('embedding_model', trim((string) $form_state->getValue('embedding_model')))
      ->set('top_k', (int) $form_state->getValue('top_k'))
      ->set('min_score', (float) $form_state->getValue('min_score'))
      ->set('monthly_token_limit', (int) $form_state->getValue('monthly_token_limit'))
      ->set('kosten_pro_1k_chf', (float) $form_state->getValue('kosten_pro_1k_chf'))
      ->set('safety_terms', $terms)
      ->save();
    // Limit-Anteil sofort neu rechnen.
    Cache::invalidateTags([LimitService::CACHE_TAG]);
    parent::submitForm($form, $form_state);
  }

  /**
   * Nicht leere, getrimmte Zeilen ohne Doppelte.
   *
   * @return string[]
   *   Zeilen.
   */
  private static function lines(string $text): array {
    $lines = array_map('trim', preg_split('/\R/u', $text) ?: []);
    return array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
  }

}
