<?php

declare(strict_types=1);

namespace Drupal\tannwald_qwen\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DashScope: Key-Entity wählen; Endpoint und Schlüssel nur als gesetzt/leer.
 *
 * Der Endpoint ist nicht editierbar — er kommt aus DASHSCOPE_ENDPOINT_EU
 * (settings.php-Override). Gezeigt wird nur der Host.
 */
final class DashScopeConfigForm extends ConfigFormBase {

  public const CONFIG = 'tannwald_qwen.settings';

  /**
   * Plugin-Manager des AI-Moduls.
   */
  protected PluginManagerInterface $aiProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->aiProvider = $container->get('ai.provider');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tannwald_qwen_settings';
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
    $provider = $this->aiProvider->createInstance('dashscope');
    $endpoint = (string) $provider->getConfiguredEndpoint();
    $host = $endpoint !== '' ? (string) parse_url($endpoint, PHP_URL_HOST) : '';
    $keySet = (bool) $provider->hasKeyValue();

    $form['status'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Status'),
      '#items' => [
        $this->t('Endpoint (DASHSCOPE_ENDPOINT_EU): @value', ['@value' => $host !== '' ? $host : $this->t('empty', [], ['context' => 'tannwald_status'])]),
        $this->t('API key (DASHSCOPE_API_KEY_EU): @value', ['@value' => $keySet ? $this->t('set', [], ['context' => 'tannwald_status']) : $this->t('empty', [], ['context' => 'tannwald_status'])]),
      ],
    ];
    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Key entity with the provider "Environment"; the value is never stored in configuration.'),
      '#default_value' => $this->config(self::CONFIG)->get('api_key'),
      '#required' => TRUE,
    ];
    if ($host !== '' && $keySet) {
      $form['models'] = [
        '#type' => 'details',
        '#title' => $this->t('Models of the endpoint (GET /models, cached for one hour)'),
        'list' => [
          '#theme' => 'item_list',
          '#items' => array_values($provider->getConfiguredModels()),
          '#empty' => $this->t('The endpoint did not return any models.'),
        ],
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG)
      ->set('api_key', (string) $form_state->getValue('api_key'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
