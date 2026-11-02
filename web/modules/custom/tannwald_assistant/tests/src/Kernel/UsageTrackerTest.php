<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\Tests\tannwald_assistant\Doubles\ChatProviderDouble;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_assistant\Usage\UsageTracker;
use PHPUnit\Framework\Attributes\Group;

/**
 * UsageTracker: genau eine Usage-Zeile je Antwort, nur Assistent-Aufrufe.
 */
#[Group('tannwald_assistant')]
final class UsageTrackerTest extends AssistantKernelTestBase {

  /**
   * Kontext mit einem Treffer.
   */
  private static function context(): RetrievalResult {
    return new RetrievalResult('Wie oft?', 'de', 'hybrid', [[
      'chunk_id' => 1,
      'nid' => 999999,
      'langcode' => 'de',
      'title' => 'Betriebsanleitung TB-200',
      'version' => '3.2',
      'heading_path' => '7 Reinigung › 7.3 Reinigung Siegelstation',
      'text' => 'Die Siegelstation wird täglich trocken gereinigt.',
    ],
    ]);
  }

  /**
   * Double, das wie der ProviderProxy das Event auslöst.
   */
  private function dispatchingChat(string $reply): void {
    $double = new ChatProviderDouble($reply, FALSE, $this->container->get('event_dispatcher'));
    $this->aiProvider->method('createInstance')->willReturn($double);
  }

  /**
   * Eine Antwort → genau ein record() mit Modell, Tokens, Kontext, Kosten.
   */
  public function testExactlyOneRowPerAnswer(): void {
    $this->config('tannwald_assistant.settings')->set('providers.ollama.kosten_pro_1k_chf', 0.5)->save();
    $this->dispatchingChat('Täglich trocken [1].');
    $this->usage->expects($this->once())->method('record')
      ->with('qwen2.5:7b', 'chat', 812, 64, 'assistent', 0.438);
    $this->container->get('tannwald_assistant.answer')
      ->answerWithContext('Wie oft muss die Siegelstation gereinigt werden?', self::context(), $this->createUser([], 'u1'), 's');
  }

  /**
   * Ablehnung nach dem Chat zählt ebenfalls genau einmal (Tokens verbraucht).
   */
  public function testRefusedAnswerCountsOnce(): void {
    $this->dispatchingChat('Ohne Quelle.');
    $this->usage->expects($this->once())->method('record');
    $this->container->get('tannwald_assistant.answer')
      ->answerWithContext('Wie oft muss die Siegelstation gereinigt werden?', self::context(), $this->createUser([], 'u2'), 's');
  }

  /**
   * Sicherheitsvorlage: kein Chat, keine Zeile.
   */
  public function testSafetyWritesNothing(): void {
    $this->aiProvider->expects($this->never())->method('createInstance');
    $this->usage->expects($this->never())->method('record');
    $this->container->get('tannwald_assistant.answer')
      ->answerWithContext('Darf ich die Schutzhaube offen lassen?', self::context(), $this->createUser([], 'u3'), 's');
  }

  /**
   * Fremde Chat-Aufrufe (D's Zusammenfassungen) zählt der Tracker nicht.
   */
  public function testForeignTagsAreIgnored(): void {
    $this->usage->expects($this->never())->method('record');
    $this->dispatchEvent(['tannwald_ingest', 'tannwald_summary']);
  }

  /**
   * Kontext aus dem Request-Attribut (4.3 setzt eval).
   */
  public function testKontextFromRequestAttribute(): void {
    $this->container->get('request_stack')->getCurrentRequest()->attributes->set(UsageTracker::KONTEXT_ATTRIBUTE, 'eval');
    $this->usage->expects($this->once())->method('record')
      ->with('qwen2.5:7b', 'chat', 10, 5, 'eval', 0.0);
    $this->dispatchEvent(['tannwald_assistant']);
  }

  /**
   * Löst das Event wie der ProviderProxy aus (10 + 5 Tokens).
   *
   * @param string[] $tags
   *   Tags des Chat-Aufrufs.
   */
  private function dispatchEvent(array $tags): void {
    $raw = ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
    $output = new ChatOutput(new ChatMessage('assistant', 'x'), $raw, []);
    $event = new PostGenerateResponseEvent('t', 'ollama', 'chat', [], NULL, 'qwen2.5:7b', $output, $tags);
    $this->container->get('event_dispatcher')->dispatch($event, PostGenerateResponseEvent::EVENT_NAME);
  }

}
