<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant_test;

use Drupal\Core\State\StateInterface;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;

/**
 * Chat-Double: Antwort aus State tannwald_assistant_test.reply.
 */
final class FakeChatProvider {

  public const DEFAULT_REPLY = 'Die Siegelstation wird täglich trocken und wöchentlich nass gereinigt [1].';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Wie ProviderProxy::setConfiguration().
   */
  public function setConfiguration(array $configuration): void {
    $this->state->set('tannwald_assistant_test.configuration', $configuration);
  }

  /**
   * Wie ProviderProxy::chat().
   */
  public function chat(mixed $input, string $model, array $tags = []): ChatOutput {
    $this->state->set('tannwald_assistant_test.chat_calls', (int) $this->state->get('tannwald_assistant_test.chat_calls', 0) + 1);
    $reply = (string) $this->state->get('tannwald_assistant_test.reply', self::DEFAULT_REPLY);
    $raw = ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
    return new ChatOutput(new ChatMessage('assistant', $reply), $raw, [], new TokenUsageDto(input: 100, output: 20, total: 120));
  }

}
