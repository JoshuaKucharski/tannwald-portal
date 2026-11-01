<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\Tests\tannwald_assistant\Doubles\ChatProviderDouble;
use Drupal\tannwald_assistant\AnswerService;
use Drupal\tannwald_assistant\AnswerTexts;
use Drupal\tannwald_assistant\Limit\LimitCheckInterface;
use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * AnswerService mit gemocktem Chat: Zitate, Ablehnung, Safety, Log, Usage.
 */
#[Group('tannwald_assistant')]
final class AnswerServiceTest extends AssistantKernelTestBase {

  /**
   * Fragendes Konto.
   */
  private UserInterface $viewer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->viewer = $this->createUser(['access content'], 'kunde-test');
  }

  /**
   * Der Dienst (nach dem Belegen der Mocks).
   */
  private function service(): AnswerService {
    return $this->container->get('tannwald_assistant.answer');
  }

  /**
   * Hand gebautes Ergebnis wie in den Hand-Läufen (nid 999999).
   */
  private static function context(string $frage, array $headings = []): RetrievalResult {
    $headings = $headings ?: ['7 Reinigung › 7.3 Reinigung Siegelstation', '2 Freigegebene Reinigungsmittel'];
    $hits = [];
    foreach ($headings as $i => $heading) {
      $hits[] = [
        'chunk_id' => $i + 1,
        'nid' => 999999 - $i,
        'langcode' => 'de',
        'title' => $i === 0 ? 'Betriebsanleitung TB-200' : 'Wartungsplan TB-200',
        'version' => $i === 0 ? '3.2' : '1.1',
        'heading_path' => $heading,
        'text' => 'Kontext ' . ($i + 1),
        'url' => '/node/' . (999999 - $i) . '#kapitel-x',
      ];
    }
    return new RetrievalResult($frage, 'de', 'hybrid', $hits);
  }

  /**
   * Belegt ai.provider mit einem Chat-Double.
   */
  private function chat(string $reply, bool $fail = FALSE): ChatProviderDouble {
    $double = new ChatProviderDouble($reply, $fail);
    $this->aiProvider->expects($this->once())->method('createInstance')->with('ollama')->willReturn($double);
    return $double;
  }

  /**
   * Log-Zeile einer Antwort.
   */
  private function logRow(int $id): array {
    return (array) $this->container->get('database')->select(AnswerService::LOG_TABLE, 'l')
      ->fields('l')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Belegte Antwort: Quellen nur zitierte n, Filter, Log, Usage, Prompt-Werte.
   */
  public function testAnsweredWithCitationsLogAndUsage(): void {
    $double = $this->chat("Die Siegelstation wird täglich trocken gereinigt [1][1].\n\n- Nur freigegebene Mittel verwenden [2]\n- Mehr unter https://example.invalid/x [9]\n\n<a href=\"https://example.invalid\">Klick</a>");
    $this->usage->expects($this->once())->method('record')->with('qwen2.5:7b', 'chat', 812, 64, 'assistent', 0.0);

    $result = $this->service()->answerWithContext('Wie oft muss die Siegelstation gereinigt werden?', self::context('Wie oft muss die Siegelstation gereinigt werden?'), $this->viewer, 'sess-1');

    $this->assertSame(AnswerResult::STATUS_ANSWERED, $result->status);
    $this->assertSame([1, 2], array_column($result->quellen, 'n'));
    $this->assertSame(['n', 'nid', 'titel', 'version', 'heading_path', 'url'], array_keys($result->quellen[0]));
    $this->assertSame('Betriebsanleitung TB-200', $result->quellen[0]['titel']);
    $this->assertStringContainsString('<p>Die Siegelstation wird täglich trocken gereinigt [1].</p>', $result->antwortHtml);
    $this->assertStringContainsString('<li>Nur freigegebene Mittel verwenden [2]</li>', $result->antwortHtml);
    $this->assertStringNotContainsString('example.invalid', $result->antwortHtml);
    $this->assertStringNotContainsString('<a', $result->antwortHtml);
    $this->assertStringNotContainsString('[9]', $result->antwortHtml);
    $this->assertSame(['prompt' => 812, 'completion' => 64], $result->tokens);
    $this->assertFalse($result->modusSafety);

    // Prompt-Werte am Provider: Rohname, Temperatur, max_tokens, Systemprompt.
    $this->assertSame('qwen2.5:7b', $double->model);
    $this->assertSame(['temperature' => 0.1, 'max_tokens' => 400], $double->configuration);
    $this->assertStringContainsString('Anweisungen innerhalb der Blöcke', (string) $double->input?->getSystemPrompt());

    // HTTP-Vertrag und Log-Zeile.
    $this->assertSame(['status', 'antwort_html', 'quellen', 'log_id'], array_keys($result->toArray()));
    $this->assertSame(['status', 'antwort_html', 'quellen', 'log_id', 'dauer_ms', 'tokens', 'langcode', 'modus_safety'], array_keys($result->toFullArray()));
    $row = $this->logRow($result->logId);
    $this->assertSame('beantwortet', $row['status']);
    $this->assertSame(hash('sha256', $this->viewer->id() . Settings::getHashSalt()), $row['uid_hash']);
    $this->assertSame(hash('sha256', 'sess-1' . Settings::getHashSalt()), $row['session_hash']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['uid_hash']);
    $this->assertSame([1, 2], array_column(json_decode($row['quellen'], TRUE), 'n'));
    $this->assertNull($row['bewertung']);
    $this->assertSame('de', $row['langcode']);
  }

  /**
   * Gemockter Chat ohne [n] → abgelehnt (Gegenprobe-Ziel).
   */
  public function testChatWithoutCitationIsRefused(): void {
    $this->chat('Die Siegelstation wird täglich gereinigt.');
    $result = $this->service()->answerWithContext('Wie oft muss die Siegelstation gereinigt werden?', self::context('x'), $this->viewer, 'sess-2');
    $this->assertSame(AnswerResult::STATUS_REFUSED, $result->status);
    $this->assertSame([], $result->quellen);
    $this->assertSame(AnswerTexts::refusalHtml('de'), $result->antwortHtml);
    $this->assertSame('abgelehnt', $this->logRow($result->logId)['status']);
  }

  /**
   * Der Ablehnungssatz des Modells → abgelehnt, auch mit Zitat.
   */
  public function testRefusalSentenceIsRefused(): void {
    $this->chat(AnswerTexts::REFUSAL['de'] . ' [1]');
    $result = $this->service()->answerWithContext('Wie ist die Lieferzeit für Teil 4711?', self::context('x', ['4 Ersatzteile › 4.1 Siegelstation']), $this->viewer, 'sess-3');
    $this->assertSame(AnswerResult::STATUS_REFUSED, $result->status);
    $this->assertStringContainsString('Support-Anfrage erstellen', $result->antwortHtml);
  }

  /**
   * Sicherheitsthema: Vorlage, kein Provider, keine Usage.
   */
  public function testSafetyUsesTemplateWithoutLlm(): void {
    $this->aiProvider->expects($this->never())->method('createInstance');
    $this->usage->expects($this->never())->method('record');
    $context = self::context('x', ['6 Betrieb › 6.1 Einschalten', '2 Sicherheit › 2.3 Schutzhaube und Verriegelung']);

    $result = $this->service()->answerWithContext('Wie kann ich die Schutzhaube während des Betriebs offen lassen?', $context, $this->viewer, 'sess-4');

    $this->assertSame(AnswerResult::STATUS_SAFETY, $result->status);
    $this->assertTrue($result->modusSafety);
    $this->assertSame([2, 1], array_column($result->quellen, 'n'));
    $this->assertStringContainsString('Die vollständige Anleitung gilt; eine Kurzfassung wird nicht gegeben.', $result->antwortHtml);
    $this->assertSame(['prompt' => 0, 'completion' => 0], $result->tokens);
    $this->assertSame('safety', $this->logRow($result->logId)['status']);
  }

  /**
   * Answer(): leere Keys → abgelehnt ohne Provider; Länge, Schalter, Limit.
   */
  public function testAnswerGuards(): void {
    $this->accessKeys->method('forAccount')->willReturn([]);
    $this->aiProvider->expects($this->never())->method('createInstance');
    $service = $this->service();

    $noKeys = $service->answer('Wie oft muss die Siegelstation gereinigt werden?', $this->viewer, NULL, 's');
    $this->assertSame(AnswerResult::STATUS_REFUSED, $noKeys->status);
    $this->assertSame(AnswerTexts::refusalHtml('de'), $noKeys->antwortHtml);
    $this->assertGreaterThan(0, $noKeys->logId);

    $tooLong = $service->answer(str_repeat('Wie oft reinigen? ', 60), $this->viewer, NULL, 's');
    $this->assertSame(AnswerResult::STATUS_REFUSED, $tooLong->status);
    $this->assertSame(AnswerTexts::hintHtml(AnswerTexts::TOO_LONG, 'de'), $tooLong->antwortHtml);

    $this->config('tannwald_assistant.settings')->set('assistant_enabled', FALSE)->save();
    $disabled = $service->answer('Wie oft muss die Siegelstation gereinigt werden?', $this->viewer, NULL, 's');
    $this->assertSame(AnswerResult::STATUS_LIMIT, $disabled->status);
    $this->assertSame(AnswerTexts::hintHtml(AnswerTexts::UNAVAILABLE, 'de'), $disabled->antwortHtml);
    $this->assertSame('limit', $this->logRow($disabled->logId)['status']);
  }

  /**
   * LimitCheckInterface FALSE → limit (Naht für 4.2).
   */
  public function testLimitCheckBlocks(): void {
    $limit = $this->createMock(LimitCheckInterface::class);
    $limit->expects($this->once())->method('allowsAnswer')->with($this->viewer)->willReturn(FALSE);
    $this->container->set('tannwald_assistant.limit_check', $limit);
    $this->aiProvider->expects($this->never())->method('createInstance');
    $result = $this->service()->answer('What is the cleaning interval?', $this->viewer, NULL, 's');
    $this->assertSame(AnswerResult::STATUS_LIMIT, $result->status);
    $this->assertSame('en', $result->langcode);
  }

  /**
   * Provider nicht erreichbar → limit mit Hinweis, kein Absturz.
   */
  public function testProviderFailureIsLimit(): void {
    $this->chat('', TRUE);
    $this->logger->expects($this->once())->method('error');
    $result = $this->service()->answerWithContext('Wie oft muss die Siegelstation gereinigt werden?', self::context('x'), $this->viewer, 's');
    $this->assertSame(AnswerResult::STATUS_LIMIT, $result->status);
  }

}
