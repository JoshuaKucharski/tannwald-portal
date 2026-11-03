<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tannwald_assistant\AnswerTexts;
use Drupal\tannwald_assistant\Limit\LimitService;
use Drupal\tannwald_assistant\Provider\ProviderResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Konfiguration „Assistent“ unter /admin/config/tannwald/assistent (4.2 h).
 *
 * Seit 3.1: Provider je Operation (Chat, Embeddings) und Modelle/Kosten je
 * Provider; DashScope nur wählbar mit Endpoint und Schlüssel (gesetzt/leer).
 */
final class AssistantSettingsForm extends ConfigFormBase {

  public const CONFIG = 'tannwald_assistant.settings';

  /**
   * Provider-Auflösung.
   */
  protected ProviderResolver $providerResolver;

  /**
   * Plugin-Manager des AI-Moduls (Status von DashScope).
   */
  protected PluginManagerInterface $aiProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->providerResolver = $container->get('tannwald_assistant.provider_resolver');
    $form->aiProvider = $container->get('ai.provider');
    return $form;
  }

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
    $options = [
      'ollama' => $this->t('Ollama (local)'),
      'dashscope' => $this->t('DashScope (Qwen, API)'),
    ];
    $status = $this->dashscopeStatus();
    $form['provider_status'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Provider status'),
      '#items' => [
        $this->t('Active for chat: @provider', ['@provider' => $options[$this->providerResolver->providerId('chat')] ?? '']),
        $this->t('Active for embeddings: @provider', ['@provider' => $options[$this->providerResolver->providerId('embeddings')] ?? '']),
        $this->t('DashScope endpoint (DASHSCOPE_ENDPOINT_EU): @value', ['@value' => $status['host'] !== '' ? $status['host'] : $this->t('empty', [], ['context' => 'tannwald_status'])]),
        $this->t('DashScope API key (DASHSCOPE_API_KEY_EU): @value', ['@value' => $status['key'] ? $this->t('set', [], ['context' => 'tannwald_status']) : $this->t('empty', [], ['context' => 'tannwald_status'])]),
      ],
    ];
    $form['provider_chat'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider for chat'),
      '#options' => $options,
      '#default_value' => $this->providerResolver->providerId('chat'),
      '#required' => TRUE,
    ];
    $form['provider_embeddings'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider for embeddings'),
      '#description' => $this->t('Changing the embedding provider or model requires <code>drush tannwald:reindex</code> and <code>drush tannwald:queue-run</code>: stored vectors belong to the old model, and search silently returns poor hits until then.'),
      '#options' => $options,
      '#default_value' => $this->providerResolver->providerId('embeddings'),
      '#required' => TRUE,
    ];
    $form['providers'] = ['#tree' => TRUE];
    foreach (ProviderResolver::PROVIDERS as $id) {
      $values = $this->providerResolver->providerSettings($id);
      $form['providers'][$id] = [
        '#type' => 'details',
        '#title' => $options[$id],
        '#open' => FALSE,
      ];
      $form['providers'][$id]['chat_model'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Chat model'),
        '#description' => $this->t('Raw model name of the provider, e.g. qwen2.5:7b or qwen-plus.'),
        '#default_value' => $values['chat_model'],
        '#required' => TRUE,
      ];
      $form['providers'][$id]['embedding_model'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Embedding model'),
        '#description' => $this->t('A change requires a reindex (see above).'),
        '#default_value' => $values['embedding_model'],
        '#required' => TRUE,
      ];
      $form['providers'][$id]['embedding_dimensions'] = [
        '#type' => 'number',
        '#title' => $this->t('Embedding dimensions'),
        '#description' => $this->t('Fixed by the chunk store.'),
        '#default_value' => $values['embedding_dimensions'],
        '#disabled' => TRUE,
      ];
      $form['providers'][$id]['kosten_pro_1k_chf'] = [
        '#type' => 'number',
        '#title' => $this->t('Cost per 1,000 tokens (CHF)'),
        '#description' => $id === 'dashscope'
          ? $this->t('Chat tokens of this provider; embeddings are recorded without cost. Placeholder 0.0008: Alibaba Model Studio list price for qwen-plus (Germany/Frankfurt, EU, read 02.11.2026: input USD 0.40, output USD 1.20 per million tokens), weighted input to output 1:3, at 0.8196 CHF per USD (ECB, 02.11.2026). With the measured token mix (about 40:1) the actual cost is about 2.3 times lower. The operator confirms the value.')
          : $this->t('Chat tokens of this provider; embeddings are recorded without cost.'),
        '#min' => 0,
        '#step' => 0.0001,
        '#default_value' => $values['kosten_pro_1k_chf'],
        '#required' => TRUE,
      ];
    }
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
    $status = $this->dashscopeStatus();
    foreach (ProviderResolver::SETTING_KEYS as $key) {
      $provider = (string) $form_state->getValue($key);
      if (!in_array($provider, ProviderResolver::PROVIDERS, TRUE)) {
        $form_state->setErrorByName($key, $this->t('Unknown provider.'));
      }
      elseif ($provider === 'dashscope' && !$status['usable']) {
        $form_state->setErrorByName($key, $this->t('DashScope cannot be selected: the API key or the endpoint is empty (DASHSCOPE_API_KEY_EU, DASHSCOPE_ENDPOINT_EU).'));
      }
      elseif ($provider === 'dashscope' && $key === 'provider_embeddings' && $this->dashscopeEmbeddingModels() === []) {
        // D11: der EU-Endpoint meldet kein Embedding-Modell (GET /models).
        $form_state->setErrorByName($key, $this->t('DashScope cannot be selected for embeddings: the endpoint does not offer an embedding model (GET /models).'));
      }
    }
    foreach (ProviderResolver::PROVIDERS as $id) {
      foreach (['chat_model', 'embedding_model'] as $key) {
        if (!preg_match('/^[A-Za-z0-9._:\/-]+$/', (string) $form_state->getValue(['providers', $id, $key]))) {
          $form_state->setErrorByName('providers][' . $id . '][' . $key, $this->t('Only letters, digits and . _ : / - are allowed.'));
        }
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
    $before = $this->embeddingIdentity();
    $providers = [];
    foreach (ProviderResolver::PROVIDERS as $id) {
      $providers[$id] = [
        'chat_model' => trim((string) $form_state->getValue(['providers', $id, 'chat_model'])),
        'embedding_model' => trim((string) $form_state->getValue(['providers', $id, 'embedding_model'])),
        'embedding_dimensions' => $this->providerResolver->providerSettings($id)['embedding_dimensions'],
        'kosten_pro_1k_chf' => (float) $form_state->getValue(['providers', $id, 'kosten_pro_1k_chf']),
      ];
    }
    $this->config(self::CONFIG)
      ->set('assistant_enabled', (bool) $form_state->getValue('assistant_enabled'))
      ->set('provider_chat', (string) $form_state->getValue('provider_chat'))
      ->set('provider_embeddings', (string) $form_state->getValue('provider_embeddings'))
      ->set('providers', $providers)
      ->set('top_k', (int) $form_state->getValue('top_k'))
      ->set('min_score', (float) $form_state->getValue('min_score'))
      ->set('monthly_token_limit', (int) $form_state->getValue('monthly_token_limit'))
      ->set('safety_terms', $terms)
      // Flache Schlüssel aus der Zeit vor update 10006 entfernen.
      ->clear('provider')
      ->clear('chat_model')
      ->clear('embedding_model')
      ->clear('kosten_pro_1k_chf')
      ->save();
    if ($this->embeddingIdentity() !== $before) {
      $this->messenger()->addWarning($this->t('The embedding provider or model has changed. Run <code>drush tannwald:reindex</code> and <code>drush tannwald:queue-run</code>; until then search results are unreliable.'));
    }
    // Limit-Anteil sofort neu rechnen.
    Cache::invalidateTags([LimitService::CACHE_TAG]);
    parent::submitForm($form, $form_state);
  }

  /**
   * DashScope: nutzbar, Endpoint-Host, Schlüssel gesetzt — nie der Wert.
   *
   * @return array{usable: bool, host: string, key: bool}
   *   Status; alles leer/FALSE ohne Modul tannwald_qwen.
   */
  private function dashscopeStatus(): array {
    $status = ['usable' => FALSE, 'host' => '', 'key' => FALSE];
    if (!$this->aiProvider->hasDefinition('dashscope')) {
      return $status;
    }
    try {
      $provider = $this->aiProvider->createInstance('dashscope');
      $status['host'] = (string) parse_url((string) $provider->getConfiguredEndpoint(), PHP_URL_HOST);
      $status['key'] = (bool) $provider->hasKeyValue();
      $status['usable'] = (bool) $provider->isUsable();
    }
    catch (\Throwable) {
      // Plugin nicht ladbar: nicht nutzbar.
    }
    return $status;
  }

  /**
   * Embedding-Modelle, die der DashScope-Endpoint meldet (GET /models, Cache).
   *
   * @return string[]
   *   Modell-IDs; leer ohne Plugin oder ohne Embedding-Modell.
   */
  private function dashscopeEmbeddingModels(): array {
    try {
      return array_keys((array) $this->aiProvider->createInstance('dashscope')->getConfiguredModels('embeddings'));
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Provider und Modell der Embeddings (für die Reindex-Warnung).
   */
  private function embeddingIdentity(): string {
    $id = $this->providerResolver->providerId('embeddings');
    return $id . ':' . $this->providerResolver->providerSettings($id)['embedding_model'];
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
