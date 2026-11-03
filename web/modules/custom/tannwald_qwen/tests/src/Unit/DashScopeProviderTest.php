<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_qwen\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\tannwald_qwen\Plugin\AiProvider\DashScopeProvider;
use Drupal\tannwald_qwen\SecretScrubber;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * DashScope-Provider gegen einen gemockten HTTP-Client (nie live).
 */
#[Group('tannwald_qwen')]
final class DashScopeProviderTest extends UnitTestCase {

  /**
   * Erfundener Test-Schlüssel im Format echter Schlüssel (kein echter Wert).
   */
  private const SECRET = 'sk-0123456789abcdef0123456789abcdef';

  private const ENDPOINT = 'https://ws-test.eu-central-1.maas.aliyuncs.com/compatible-mode/v1';

  /**
   * Gesendete Anfragen (Guzzle-History).
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $history = [];

  /**
   * Sammelt die ins Log geschriebenen, interpolierten Meldungen.
   */
  private object $collector;

  /**
   * Baut den Provider mit gemockten Antworten.
   *
   * @param array<int, \Psr\Http\Message\ResponseInterface|\Throwable> $responses
   *   Antworten in Reihenfolge.
   * @param string $secret
   *   Wert der Key-Entity (leer = Schlüssel nicht gesetzt).
   * @param string $endpoint
   *   Endpoint aus der Konfiguration (leer = nicht gesetzt).
   */
  private function provider(array $responses, string $secret = self::SECRET, string $endpoint = self::ENDPOINT): DashScopeProvider {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($secret);
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->with('dashscope_api_key')->willReturn($key);
    $this->collector = new class() extends AbstractLogger {

      /**
       * Interpolierte Meldungen.
       *
       * @var string[]
       */
      public array $messages = [];

      /**
       * Schreibt die interpolierte Meldung in die Senke.
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->messages[] = strtr((string) $message, array_filter($context, 'is_scalar'));
      }

    };
    $channel = new LoggerChannel('tannwald_qwen');
    $channel->addLogger($this->collector);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($channel);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    return new DashScopeProvider(
      'dashscope',
      ['id' => 'dashscope', 'label' => 'DashScope (Qwen)', 'provider' => 'tannwald_qwen'],
      new Client(['handler' => $stack]),
      $this->getConfigFactoryStub([
        'tannwald_qwen.settings' => ['api_key' => 'dashscope_api_key', 'endpoint' => $endpoint],
      ]),
      $loggers,
      $cache,
      $keys,
      $this->createMock(ModuleHandlerInterface::class),
      new EventDispatcher(),
      $this->createMock(FileSystemInterface::class),
    );
  }

  /**
   * JSON-Antwort.
   */
  private static function json(int $status, array $body): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Chat: Antworttext, Tokens, URL, Bearer, Payload ohne Streaming.
   */
  public function testChatParsesAnswerAndUsage(): void {
    $provider = $this->provider([self::json(200, [
      'id' => 'chatcmpl-1',
      'object' => 'chat.completion',
      'created' => 1762090000,
      'model' => 'qwen-plus',
      'choices' => [
        [
          'index' => 0,
          'message' => ['role' => 'assistant', 'content' => 'bereit'],
          'finish_reason' => 'stop',
        ],
      ],
      'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 2, 'total_tokens' => 32],
    ]),
    ]);
    $provider->setConfiguration(['temperature' => 0.1, 'max_tokens' => 20]);
    $input = new ChatInput([
      new ChatMessage('system', 'Kurz.'),
      new ChatMessage('user', 'Antworte mit dem Wort bereit.'),
    ]);
    $output = $provider->chat($input, 'qwen-plus');

    $this->assertSame('bereit', $output->getNormalized()->getText());
    $usage = $output->getTokenUsage();
    $this->assertSame([30, 2, 32], [$usage->input, $usage->output, $usage->total]);
    $this->assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame(self::ENDPOINT . '/chat/completions', (string) $request->getUri());
    $this->assertTrue($request->getHeaderLine('Authorization') === 'Bearer ' . self::SECRET, 'Bearer header carries the key from the key module.');
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('qwen-plus', $body['model']);
    $this->assertSame(['system', 'user'], array_column($body['messages'], 'role'));
    $this->assertSame(0.1, $body['temperature']);
    $this->assertSame(20, $body['max_tokens']);
    $this->assertFalse($body['enable_thinking']);
    $this->assertArrayNotHasKey('stream', $body);
  }

  /**
   * Embeddings: 23 Texte in Aufrufen zu 10/10/3.
   *
   * Dimension 1024, Reihenfolge nach index, usage summiert.
   */
  public function testEmbeddingsSplitBatchesAndSendDimensions(): void {
    $responses = [];
    foreach ([10, 10, 3] as $batch => $count) {
      $data = [];
      for ($i = 0; $i < $count; $i++) {
        $vector = array_fill(0, 1024, 0.0);
        $vector[0] = (float) ($batch * 10 + $i);
        // Absichtlich umgekehrt geliefert: der Client sortiert nach index.
        array_unshift($data, ['object' => 'embedding', 'index' => $i, 'embedding' => $vector]);
      }
      $responses[] = self::json(200, [
        'object' => 'list',
        'model' => 'text-embedding-v3',
        'data' => $data,
        'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
      ]);
    }
    $provider = $this->provider($responses);
    $provider->setConfiguration(['dimensions' => 1024, 'temperature' => 0.5]);
    $texts = array_map(static fn(int $i): string => 'Text ' . $i, range(0, 22));
    $output = $provider->embeddingsCollection(new EmbeddingsCollectionInput($texts), 'text-embedding-v3');

    $vectors = $output->getNormalized();
    $this->assertCount(23, $vectors);
    $this->assertSame([1024], array_values(array_unique(array_map('count', $vectors))));
    $this->assertSame(array_map('floatval', range(0, 22)), array_map(static fn(array $v): float => $v[0], $vectors));
    $this->assertSame(['prompt_tokens' => 21, 'total_tokens' => 21], $output->getRawOutput()['usage']);
    $this->assertCount(3, $this->history);
    foreach ([10, 10, 3] as $n => $count) {
      $request = $this->history[$n]['request'];
      $this->assertSame(self::ENDPOINT . '/embeddings', (string) $request->getUri());
      $body = json_decode((string) $request->getBody(), TRUE);
      $this->assertCount($count, $body['input']);
      $this->assertSame(1024, $body['dimensions']);
      $this->assertSame('float', $body['encoding_format']);
      $this->assertArrayNotHasKey('temperature', $body, 'Chat parameters do not travel into embeddings.');
    }
    // Die Payload-Konfiguration des Aufrufers bleibt erhalten.
    $this->assertSame(['dimensions' => 1024, 'temperature' => 0.5], $provider->getConfiguration());
  }

  /**
   * Fehlerpfade: Ausnahmetyp, Code, kein Schlüssel in Meldung und Log.
   */
  #[DataProvider('failures')]
  public function testFailuresAreMappedAndScrubbed(\Closure $response, string $exception, int $code, string $fragment): void {
    $provider = $this->provider([$response()]);
    try {
      $provider->chat(new ChatInput([new ChatMessage('user', 'Hallo')]), 'qwen-plus');
      $this->fail('Expected ' . $exception);
    }
    catch (\Throwable $e) {
      $this->assertInstanceOf($exception, $e);
      $this->assertSame($code, $e->getCode());
      $this->assertNull($e->getPrevious(), 'No raw exception travels along.');
      $this->assertStringContainsString($fragment, $e->getMessage());
      $this->assertCount(1, $this->collector->messages);
      foreach ([$e->getMessage(), ...$this->collector->messages] as $text) {
        self::assertNoSecret($text);
      }
    }
  }

  /**
   * Antworten mit Schlüssel im Text (wie OpenAI bei 401) und ohne.
   */
  public static function failures(): array {
    $request = new Request('POST', self::ENDPOINT . '/chat/completions');
    return [
      '401 echoes key' => [
        static fn() => self::json(401, [
          'error' => [
            'message' => 'Incorrect API key provided: ' . self::SECRET . '. Bearer ' . self::SECRET,
            'type' => 'invalid_request_error',
            'code' => 'invalid_api_key',
          ],
        ]),
        AiSetupFailureException::class, 401, 'invalid_api_key',
      ],
      '429' => [
        static fn() => self::json(429, [
          'error' => ['message' => 'Requests rate limit exceeded', 'code' => 'limit_requests'],
        ]),
        AiRateLimitException::class, 429, 'HTTP 429',
      ],
      '500' => [
        static fn() => self::json(500, ['error' => ['message' => 'Internal error', 'code' => 'InternalError']]),
        AiResponseErrorException::class, 500, 'HTTP 500',
      ],
      '404 model' => [
        static fn() => self::json(404, ['error' => ['message' => 'Model not exist.', 'code' => 'model_not_found']]),
        AiRequestErrorException::class, 404, 'model_not_found',
      ],
      'timeout' => [
        static fn() => new ConnectException('cURL error 28: Operation timed out after 30001 milliseconds', $request),
        AiRequestErrorException::class, 0, 'timeout',
      ],
    ];
  }

  /**
   * Ohne Schlüssel: nicht nutzbar, kein HTTP-Aufruf, saubere Meldung.
   */
  public function testEmptyKeyIsNotUsableAndSendsNothing(): void {
    $provider = $this->provider([], '');
    $this->assertFalse($provider->isUsable());
    $this->assertFalse($provider->isUsable('chat'));
    $this->assertFalse($provider->hasKeyValue());
    $this->expectException(AiSetupFailureException::class);
    $this->expectExceptionMessage('DASHSCOPE_API_KEY_EU is empty');
    try {
      $provider->chat(new ChatInput([new ChatMessage('user', 'Hallo')]), 'qwen-plus');
    }
    finally {
      $this->assertCount(0, $this->history);
    }
  }

  /**
   * Ohne Endpoint: nicht nutzbar.
   */
  public function testEmptyEndpointIsNotUsable(): void {
    $provider = $this->provider([], self::SECRET, '');
    $this->assertFalse($provider->isUsable('embeddings'));
    $this->assertTrue($provider->hasKeyValue());
    $this->assertSame([], $provider->getConfiguredModels('chat'));
  }

  /**
   * Modell-Liste aus GET /models, nach Operation gefiltert.
   */
  public function testModelListIsFilteredByOperation(): void {
    $models = ['qwen-plus', 'qwen3-max', 'qwen-image-3.0', 'qwen-vl-ocr', 'qwen-mt-plus', 'text-embedding-v4'];
    $data = array_map(static fn(string $id): array => [
      'id' => $id,
      'object' => 'model',
      'created' => 1,
      'owned_by' => 'system',
    ], $models);
    $provider = $this->provider([self::json(200, ['object' => 'list', 'data' => $data])]);
    $this->assertSame(['qwen-plus' => 'qwen-plus', 'qwen3-max' => 'qwen3-max'], $provider->getConfiguredModels('chat'));
    $this->assertSame(self::ENDPOINT . '/models', (string) $this->history[0]['request']->getUri());
  }

  /**
   * Scrubber: Muster und wörtlicher Schlüssel.
   */
  public function testScrubberRemovesKeyPatterns(): void {
    $text = SecretScrubber::scrub('key ' . self::SECRET . ' Bearer abc.def x ' . str_repeat('ab12', 6) . ' tail', ['geheim-wert-123']);
    $this->assertSame('key [redacted] [redacted] x [redacted] tail', $text);
    $this->assertSame('a [redacted] b', SecretScrubber::scrub('a geheim-wert-123 b', ['geheim-wert-123']));
  }

  /**
   * Kein Schlüssel und kein Schlüsselmuster im Text.
   */
  private static function assertNoSecret(string $text): void {
    self::assertStringNotContainsString(self::SECRET, $text);
    self::assertDoesNotMatchRegularExpression('/sk-[A-Za-z0-9]{4,}/', $text);
    self::assertDoesNotMatchRegularExpression('/[A-Fa-f0-9]{20,}/', $text);
  }

}
