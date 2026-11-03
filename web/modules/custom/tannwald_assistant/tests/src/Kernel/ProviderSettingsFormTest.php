<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tannwald_assistant\Form\AssistantSettingsForm;
use PHPUnit\Framework\Attributes\Group;

/**
 * Formular „Assistent“ seit 3.1: Status, DashScope-Sperre, Reindex-Warnung.
 *
 * Ai.provider ist das Mock der Basisklasse: ohne Definition „dashscope“ gilt
 * DashScope als nicht nutzbar (Endpoint und Schlüssel leer).
 */
#[Group('tannwald_assistant')]
final class ProviderSettingsFormTest extends AssistantKernelTestBase {

  /**
   * Werte wie aus dem Formular, mit Überschreibungen.
   */
  private function values(array $overrides = []): array {
    $settings = $this->config('tannwald_assistant.settings');
    $providers = $settings->get('providers');
    foreach ($providers as $id => $values) {
      unset($providers[$id]['embedding_dimensions']);
    }
    return array_replace_recursive([
      'assistant_enabled' => TRUE,
      'provider_chat' => 'ollama',
      'provider_embeddings' => 'ollama',
      'providers' => $providers,
      'top_k' => 6,
      'min_score' => 0.015,
      'monthly_token_limit' => 2000000,
      'safety_terms' => array_map(static fn(array $terms): string => implode("\n", $terms), $settings->get('safety_terms')),
    ], $overrides);
  }

  /**
   * Sendet das Formular und gibt den Zustand zurück.
   */
  private function submit(array $values): FormStateInterface {
    $state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(AssistantSettingsForm::class, $state);
    return $state;
  }

  /**
   * Status zeigt aktive Provider und DashScope leer; nie einen Wert.
   */
  public function testStatusShowsSetOrEmptyOnly(): void {
    $form = $this->container->get('form_builder')->getForm(AssistantSettingsForm::class);
    $items = array_map('strval', $form['provider_status']['#items']);
    $this->assertSame([
      'Active for chat: Ollama (local)',
      'Active for embeddings: Ollama (local)',
      'DashScope endpoint (DASHSCOPE_ENDPOINT_EU): empty',
      'DashScope API key (DASHSCOPE_API_KEY_EU): empty',
    ], $items);
    $this->assertSame(['ollama', 'dashscope'], array_keys($form['provider_embeddings']['#options']));
    $this->assertStringContainsString('tannwald:reindex', (string) $form['provider_embeddings']['#description']);
    $this->assertTrue($form['providers']['dashscope']['embedding_dimensions']['#disabled']);
  }

  /**
   * DashScope ohne Schlüssel/Endpoint ist für keine Operation wählbar.
   */
  public function testDashscopeNotSelectableWhenEmpty(): void {
    foreach (['provider_chat', 'provider_embeddings'] as $key) {
      $state = $this->submit($this->values([$key => 'dashscope']));
      $this->assertArrayHasKey($key, $state->getErrors());
      $this->assertStringContainsString('DashScope cannot be selected', (string) $state->getErrors()[$key]);
    }
    $this->assertSame('ollama', $this->config('tannwald_assistant.settings')->get('provider_chat'));
    $this->assertSame('ollama', $this->config('tannwald_assistant.settings')->get('provider_embeddings'));
  }

  /**
   * Speichern: geschachtelt, Dimension unverändert.
   *
   * Die Reindex-Warnung erscheint nur bei einem Embedding-Wechsel.
   */
  public function testSaveNestedAndWarnOnEmbeddingChange(): void {
    $messenger = $this->container->get('messenger');

    $state = $this->submit($this->values([
      'providers' => ['ollama' => ['chat_model' => 'llama3.1:8b', 'kosten_pro_1k_chf' => 0.25]],
    ]));
    $this->assertSame([], $state->getErrors());
    $this->assertSame([], $messenger->messagesByType('warning'), 'Chat model change: no reindex warning.');
    $settings = $this->config('tannwald_assistant.settings');
    $this->assertSame('llama3.1:8b', $settings->get('providers.ollama.chat_model'));
    $this->assertSame(0.25, $settings->get('providers.ollama.kosten_pro_1k_chf'));
    $this->assertSame(1024, $settings->get('providers.ollama.embedding_dimensions'));

    $this->submit($this->values(['providers' => ['ollama' => ['embedding_model' => 'nomic-embed-text']]]));
    $warnings = array_map('strval', $messenger->all()['warning'] ?? []);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('tannwald:reindex', $warnings[0]);
  }

  /**
   * D11: DashScope nutzbar, aber ohne Embedding-Modell am Endpoint.
   *
   * Chat ist wählbar, Embeddings nicht; der Status zeigt Host und „set“.
   */
  public function testDashscopeEmbeddingsBlockedWithoutModel(): void {
    $double = new class() {

      /**
       * Endpoint wie aus dem Override.
       */
      public function getConfiguredEndpoint(): string {
        return 'https://ws-test.eu-central-1.example/compatible-mode/v1';
      }

      /**
       * Schlüssel gesetzt.
       */
      public function hasKeyValue(): bool {
        return TRUE;
      }

      /**
       * Nutzbar.
       */
      public function isUsable(): bool {
        return TRUE;
      }

      /**
       * Wie der EU-Endpoint: Chat-Modelle, keine Embedding-Modelle.
       */
      public function getConfiguredModels(?string $operation = NULL): array {
        return $operation === 'embeddings' ? [] : ['qwen-plus' => 'qwen-plus'];
      }

    };
    $this->aiProvider->method('hasDefinition')->willReturn(TRUE);
    $this->aiProvider->method('createInstance')->willReturn($double);

    $form = $this->container->get('form_builder')->getForm(AssistantSettingsForm::class);
    $items = array_map('strval', $form['provider_status']['#items']);
    $this->assertSame('DashScope endpoint (DASHSCOPE_ENDPOINT_EU): ws-test.eu-central-1.example', $items[2]);
    $this->assertSame('DashScope API key (DASHSCOPE_API_KEY_EU): set', $items[3]);

    $state = $this->submit($this->values(['provider_chat' => 'dashscope', 'provider_embeddings' => 'dashscope']));
    $this->assertSame(['provider_embeddings'], array_keys($state->getErrors()));
    $this->assertStringContainsString('does not offer an embedding model', (string) $state->getErrors()['provider_embeddings']);

    $state = $this->submit($this->values(['provider_chat' => 'dashscope']));
    $this->assertSame([], $state->getErrors());
    $this->assertSame('dashscope', $this->config('tannwald_assistant.settings')->get('provider_chat'));
  }

}
