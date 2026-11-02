<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Doubles;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Plugin\ProviderProxy;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Double für den Rückgabewert von ai.provider->createInstance() (nie Ollama).
 *
 * Nimmt setConfiguration() und chat() entgegen, merkt sich Eingabe, Modell
 * und Konfiguration und liefert eine feste Antwort mit Token-Zahlen.
 */
final class ChatProviderDouble extends ProviderProxy {

  /**
   * Gesetzte Provider-Konfiguration.
   */
  public array $configuration = [];

  /**
   * Letzte Chat-Eingabe.
   */
  public ?ChatInput $input = NULL;

  /**
   * Letztes Modell.
   */
  public ?string $model = NULL;

  /**
   * Tags des letzten chat()-Aufrufs.
   */
  public array $tags = [];

  /**
   * Anzahl chat()-Aufrufe.
   */
  public int $calls = 0;

  /**
   * Antworten je Aufruf (leer: immer $reply).
   *
   * @var string[]
   */
  public array $queue = [];

  /**
   * Erzeugt das Double ohne Dienste.
   *
   * @param string $reply
   *   Antworttext des „Modells“.
   * @param bool $fail
   *   TRUE: chat() wirft (Provider nicht erreichbar).
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface|null $dispatcher
   *   Gesetzt: chat() löst wie der ProviderProxy das Event
   *   PostGenerateResponseEvent aus (UsageTrackerTest).
   */
  public function __construct(
    public string $reply = '',
    public bool $fail = FALSE,
    private readonly ?EventDispatcherInterface $dispatcher = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function __call($name, $arguments) {
    if ($name === 'setConfiguration') {
      $this->configuration = $arguments[0];
      return NULL;
    }
    if ($name === 'chat') {
      $this->calls++;
      if ($this->fail) {
        throw new \RuntimeException('Connection refused');
      }
      $this->input = $arguments[0];
      $this->model = $arguments[1];
      $this->tags = $arguments[2] ?? [];
      $raw = ['usage' => ['prompt_tokens' => 812, 'completion_tokens' => 64]];
      $reply = $this->queue !== [] ? (string) array_shift($this->queue) : $this->reply;
      $output = new ChatOutput(new ChatMessage('assistant', $reply), $raw, [], new TokenUsageDto(input: 812, output: 64, total: 876));
      $this->dispatcher?->dispatch(new PostGenerateResponseEvent('thread-1', 'ollama', 'chat', $this->configuration, $this->input, (string) $this->model, $output, $this->tags), PostGenerateResponseEvent::EVENT_NAME);
      return $output;
    }
    throw new \BadMethodCallException('Unexpected provider call ' . $name);
  }

}
