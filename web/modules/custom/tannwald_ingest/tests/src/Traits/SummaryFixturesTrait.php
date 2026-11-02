<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Traits;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Plugin\QueueWorker\RetryingQueueWorkerBase;
use Drupal\Tests\tannwald_access\Traits\AccessFixturesTrait;
use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;

/**
 * Content-Modell für Zusammenfassungen und ein KI-Double (Chat, Embeddings).
 *
 * Baut auf C's AccessFixturesTrait (Bundles, Maschinentypen mit Typ-Code,
 * Rollen) auf und ergänzt, was 3.2 braucht: body, die Felder der
 * Zusammenfassung (wie live nicht übersetzbar), Format dokument_html, Workflow
 * redaktion und die Transition-Rechte aus 2.1.
 */
trait SummaryFixturesTrait {

  use AccessFixturesTrait;

  /**
   * Antwort des Chat-Doubles.
   */
  public const SUMMARY_DE = 'Die Betriebsanleitung TB-200 Version 3.2 beschreibt Aufbau, Bedienung und Wartung der Blisterlinie. '
    . 'Sicherheitsrelevant sind Kapitel 2 Sicherheit und Kapitel 7.3 zur Reinigung der Siegelstation, die vor der Nassreinigung '
    . 'das Stromlosschalten der Linie verlangt.';

  /**
   * Englische Antwort des Chat-Doubles.
   */
  public const SUMMARY_EN = 'The operating instructions TB-200 version 3.2 describe the set-up, operation and maintenance of the blister line. '
    . 'Safety-relevant are chapter 2 Safety and chapter 7.3 on cleaning the sealing station, which must be switched off before wet cleaning.';

  /**
   * Text, den das Chat-Double liefert (NULL: SUMMARY_DE/EN nach Prompt).
   */
  protected ?string $chatReply = NULL;

  /**
   * Wirft das Chat-Double?
   */
  protected bool $chatFailing = FALSE;

  /**
   * Aufgezeichnete Chat-Aufrufe: [system, user, model, configuration].
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $chatCalls = [];

  /**
   * Läuft einmal während des nächsten Chat-Aufrufs (paralleler Prozess).
   */
  protected ?\Closure $duringChat = NULL;

  /**
   * Letzte Konfiguration (setConfiguration) des Doubles.
   */
  protected array $chatConfiguration = [];

  /**
   * Legt das Content-Modell samt Workflow an.
   */
  protected function createSummaryModel(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->createContentModel();
    foreach (['dokument', 'bulletin', 'zusammenfassung'] as $bundle) {
      $this->addField('node', $bundle, 'body', 'text_with_summary');
    }
    $this->addField('node', 'zusammenfassung', 'field_dokument_revision', 'integer');
    $this->addField('node', 'zusammenfassung', 'field_modell', 'string');
    $this->addField('node', 'zusammenfassung', 'field_generiert_am', 'datetime', 1, ['datetime_type' => 'datetime']);
    foreach (['field_dokument', 'field_dokument_revision', 'field_modell', 'field_generiert_am'] as $name) {
      FieldConfig::loadByName('node', 'zusammenfassung', $name)->set('translatable', FALSE)->save();
    }
    FilterFormat::create([
      'format' => 'dokument_html',
      'name' => 'Dokument HTML',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<h2 id> <h3 id> <p> <ul> <ol> <li> <strong> <em> <table> <thead> <tbody> <tr> <th> <td>'],
        ],
      ],
    ])->save();

    $workflow = Workflow::create(['id' => 'redaktion', 'label' => 'Redaktion', 'type' => 'content_moderation']);
    $plugin = $workflow->getTypePlugin();
    $this->assertInstanceOf(ContentModerationInterface::class, $plugin);
    $plugin->addState('zur_pruefung', 'Zur Prüfung');
    $plugin->addState('zurueckgewiesen', 'Zurückgewiesen');
    $plugin->deleteTransition('create_new_draft');
    $plugin->deleteTransition('publish');
    $allStates = ['draft', 'zur_pruefung', 'zurueckgewiesen', 'published'];
    $plugin->addTransition('entwurf_speichern', 'Entwurf speichern', $allStates, 'draft');
    $plugin->addTransition('freigeben', 'Freigeben', ['zur_pruefung'], 'published');
    $plugin->addTransition('zur_pruefung_senden', 'Zur Prüfung senden', ['draft', 'zurueckgewiesen'], 'zur_pruefung');
    $plugin->addTransition('zurueckweisen', 'Zurückweisen', ['zur_pruefung'], 'zurueckgewiesen');
    foreach (['dokument', 'bulletin', 'zusammenfassung'] as $bundle) {
      $plugin->addEntityTypeAndBundle('node', $bundle);
    }
    $workflow->save();

    $grants = [
      'redaktion' => ['entwurf_speichern', 'freigeben', 'zur_pruefung_senden', 'zurueckweisen'],
      'compliance' => ['freigeben', 'zurueckweisen'],
    ];
    foreach ($grants as $rid => $transitions) {
      $role = Role::load($rid);
      foreach ($transitions as $transition) {
        $role->grantPermission("use redaktion transition $transition");
      }
      $role->grantPermission('use text format dokument_html')->save();
    }
    $this->config('tannwald_ingest.settings')
      ->set('summaries_enabled', TRUE)
      ->set('summary_max_words', 120)
      ->set('summary_input_chars', 6000)
      ->save();
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'ollama', 'model_id' => 'qwen2_5_7b'])
      ->set('default_providers.embeddings', ['provider_id' => 'ollama', 'model_id' => 'bge_m3_latest'])
      ->save();
  }

  /**
   * Body einer Betriebsanleitung mit Sicherheitskapitel und Tabelle.
   */
  protected static function documentHtml(string $langcode = 'de'): string {
    if ($langcode === 'en') {
      return '<h2>1 Design</h2><p>The TB-200 forms, seals and punches blisters.</p>'
        . '<h2>2 Safety</h2><p>Switch the line off before cleaning.</p>'
        . '<h2>7 Cleaning</h2><h3>7.3 Cleaning the sealing station</h3><p>Clean daily dry and weekly wet.</p>';
    }
    return '<h2>1 Aufbau</h2><p>Die TB-200 formt, siegelt und stanzt Blister.</p>'
      . '<h2>2 Sicherheit</h2><p>Vor der Reinigung die Linie stromlos schalten.</p>'
      . '<h2>7 Reinigung</h2><h3>7.3 Reinigung Siegelstation</h3><p>Täglich trocken, wöchentlich nass reinigen.</p>'
      . '<h2>9 Störungen</h2><table><thead><tr><th>Code</th><th>Abhilfe</th></tr></thead>'
      . '<tbody><tr><td>E-4711</td><td>Teil 4711 tauschen</td></tr><tr><td>E-1002</td><td>Haube schliessen</td></tr></tbody></table>';
  }

  /**
   * Veröffentlichtes Dokument (TB-200, Exportklasse keine).
   */
  protected function createPublishedDocument(string $title = 'Betriebsanleitung TB-200', string $langcode = 'de', array $values = []): NodeInterface {
    $type = $this->createAccessTerm('maschinentyp', 'TB-200', ['field_typ_code' => 'TB200']);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->create($values + [
      'type' => 'dokument',
      'title' => $title,
      'langcode' => $langcode,
      'body' => ['value' => self::documentHtml($langcode), 'format' => 'dokument_html'],
      'field_maschinentyp' => [$type->id()],
      'field_exportklasse' => 'keine',
      'field_version' => '3.2',
      'moderation_state' => 'published',
      'uid' => 0,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Ersetzt ai.provider durch ein Double (nie Ollama).
   *
   * Chat: SUMMARY_DE/SUMMARY_EN je nach Prompt-Sprache oder $chatReply;
   * Embeddings: deterministische Vektoren aus dem Text-Hash.
   */
  protected function mockAiProvider(): void {
    $proxy = $this->getMockBuilder('Drupal\ai\Plugin\ProviderProxy')
      ->disableOriginalConstructor()
      ->onlyMethods(['__call'])
      ->getMock();
    $proxy->method('__call')->willReturnCallback(function (string $name, array $arguments) {
      switch ($name) {
        case 'getConfiguredModels':
          return ['qwen2_5_7b' => 'qwen2.5:7b', 'bge_m3_latest' => 'bge-m3:latest'];

        case 'setConfiguration':
          $this->chatConfiguration = $arguments[0];
          return NULL;

        case 'chat':
          $input = $arguments[0];
          $user = $input instanceof ChatInput ? $input->getMessages()[0]->getText() : '';
          $system = $input instanceof ChatInput ? (string) $input->getSystemPrompt() : '';
          $this->chatCalls[] = [
            'system' => $system,
            'user' => $user,
            'model' => $arguments[1],
            'configuration' => $this->chatConfiguration,
          ];
          if ($this->chatFailing) {
            throw new \RuntimeException('Chat provider down (test double).');
          }
          if ($this->duringChat !== NULL) {
            $during = $this->duringChat;
            $this->duringChat = NULL;
            $during();
          }
          $reply = $this->chatReply ?? (str_contains($system, 'Write in English') ? self::SUMMARY_EN : self::SUMMARY_DE);
          $usage = new TokenUsageDto(input: 1500, output: 90);
          return new ChatOutput(new ChatMessage('assistant', $reply), ['model' => $arguments[1]], [], $usage);

        case 'embeddingsCollection':
          $input = $arguments[0];
          $prompts = $input instanceof EmbeddingsCollectionInput ? $input->getPrompts() : [];
          $raw = ['model' => 'bge-m3:latest', 'usage' => ['prompt_tokens' => 10]];
          return new EmbeddingsCollectionOutput(array_map([self::class, 'hashVector'], $prompts), $raw, []);
      }
      throw new \LogicException("Unexpected provider call $name.");
    });
    $manager = $this->createMock('Drupal\Component\Plugin\PluginManagerInterface');
    $manager->method('createInstance')->willReturn($proxy);
    $this->container->set('ai.provider', $manager);
  }

  /**
   * Deterministischer 1024er-Vektor aus dem Text-Hash.
   *
   * @return float[]
   *   Vektor.
   */
  public static function hashVector(string $text): array {
    $seed = crc32($text);
    $vector = [];
    for ($i = 0; $i < 1024; $i++) {
      $vector[] = sin($seed + $i * 0.37);
    }
    return $vector;
  }

  /**
   * Verarbeitet eine Queue bis leer.
   *
   * @return string[]
   *   Ergebnisse je Job.
   */
  protected function runQueue(string $name): array {
    $queue = $this->container->get('queue')->get($name);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance($name);
    $this->assertInstanceOf(RetryingQueueWorkerBase::class, $worker);
    $outcomes = [];
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
      $outcomes[] = $worker->lastOutcome();
    }
    return $outcomes;
  }

  /**
   * Jobs einer Queue (Payloads), ohne sie zu verarbeiten.
   *
   * @return array[]
   *   Payloads in Reihenfolge.
   */
  protected function queuedJobs(string $name): array {
    $rows = $this->container->get('database')->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', $name)
      ->orderBy('item_id')
      ->execute()
      ->fetchCol();
    return array_map(static fn(string $data): array => (array) unserialize($data, ['allowed_classes' => FALSE]), $rows);
  }

}
