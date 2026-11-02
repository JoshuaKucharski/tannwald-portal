<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Unit;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tannwald_assistant\Provider\ProviderResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * ProviderResolver: Provider je Operation, Modelle, Timeouts, Dimension.
 */
#[Group('tannwald_assistant')]
final class ProviderResolverTest extends UnitTestCase {

  /**
   * Settings wie config/install, mit eigenen Werten je Provider.
   */
  private const SETTINGS = [
    'provider_chat' => 'dashscope',
    'provider_embeddings' => 'ollama',
    'providers' => [
      'ollama' => [
        'chat_model' => 'qwen2.5:7b',
        'embedding_model' => 'bge-m3',
        'embedding_dimensions' => 1024,
        'kosten_pro_1k_chf' => 0.0,
      ],
      'dashscope' => [
        'chat_model' => 'qwen-plus',
        'embedding_model' => 'text-embedding-v3',
        'embedding_dimensions' => 1024,
        'kosten_pro_1k_chf' => 0.0012,
      ],
    ],
  ];

  /**
   * Aufrufe von createInstance(): [plugin_id, configuration, double].
   *
   * @var array<int, array{0: string, 1: array, 2: object}>
   */
  private array $created = [];

  /**
   * Resolver mit gegebenen Settings und einem protokollierenden Manager.
   */
  private function resolver(array $settings = self::SETTINGS): ProviderResolver {
    $this->created = [];
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('createInstance')->willReturnCallback(function (string $id, array $configuration = []): object {
      $double = new class($id) {

        /**
         * Payload-Konfiguration aus setConfiguration(), sonst NULL.
         */
        public ?array $configuration = NULL;

        public function __construct(public string $id) {}

        /**
         * Merkt sich die Payload-Konfiguration.
         */
        public function setConfiguration(array $configuration): void {
          $this->configuration = $configuration;
        }

      };
      $this->created[] = [$id, $configuration, $double];
      return $double;
    });
    return new ProviderResolver($this->getConfigFactoryStub(['tannwald_assistant.settings' => $settings]), $manager);
  }

  /**
   * Provider je Operation getrennt; Modelle aus providers.<id>.
   */
  public function testProviderAndModelPerOperation(): void {
    $resolver = $this->resolver();
    $this->assertSame('dashscope', $resolver->providerId('chat'));
    $this->assertSame('ollama', $resolver->providerId('embeddings'));
    $this->assertSame('qwen-plus', $resolver->model('chat'));
    $this->assertSame('bge-m3', $resolver->model('embeddings'));
    $this->assertSame('text-embedding-v3', $resolver->model('embeddings', 'dashscope'));
    $this->assertSame([
      'provider' => 'dashscope (chat), ollama (embeddings)',
      'chat_model' => 'qwen-plus',
      'embedding_model' => 'bge-m3',
    ], $resolver->summary());
    $this->assertSame(0.0012, $resolver->costPer1k('dashscope'));
    $this->assertSame(0.0, $resolver->costPer1k('openai'));
  }

  /**
   * DashScope: Timeouts 30/20 s, Embeddings mit dimensions; Ollama ohne.
   */
  public function testResolveSetsTimeoutsAndDimensions(): void {
    $resolver = $this->resolver();
    $chat = $resolver->chat();
    $this->assertSame(
      ['dashscope', 'chat', 'qwen-plus', 1024, 0.0012],
      [$chat->providerId, $chat->operation, $chat->model, $chat->dimensions, $chat->costPer1k],
    );
    $embeddings = $resolver->embeddings();
    $this->assertSame(['ollama', 'bge-m3'], [$embeddings->providerId, $embeddings->model]);
    $dashscopeEmbeddings = $resolver->resolve('embeddings', 'dashscope');
    $this->assertSame('text-embedding-v3', $dashscopeEmbeddings->model);
    $this->assertSame([
      ['dashscope', ['http_client_options' => ['timeout' => 30]]],
      ['ollama', []],
      ['dashscope', ['http_client_options' => ['timeout' => 20]]],
    ], array_map(static fn(array $c): array => [$c[0], $c[1]], $this->created));
    // Nur DashScope-Embeddings bekommen dimensions; Chat und Ollama nichts.
    $this->assertSame([NULL, NULL, ['dimensions' => 1024]], array_map(static fn(array $c): ?array => $c[2]->configuration, $this->created));
    $this->assertNotSame($chat->provider, $dashscopeEmbeddings->provider, 'Fresh instance per call.');
  }

  /**
   * Vor update 10006: flache Settings gelten für beide Operationen.
   */
  public function testLegacyFlatSettings(): void {
    $resolver = $this->resolver([
      'provider' => 'ollama',
      'chat_model' => 'qwen2.5:7b',
      'embedding_model' => 'bge-m3',
      'kosten_pro_1k_chf' => 0.5,
    ]);
    $this->assertSame(['ollama', 'ollama'], [$resolver->providerId('chat'), $resolver->providerId('embeddings')]);
    $this->assertSame(['qwen2.5:7b', 'bge-m3'], [$resolver->model('chat'), $resolver->model('embeddings')]);
    $this->assertSame(0.5, $resolver->costPer1k('ollama'));
    $this->assertSame(1024, $resolver->providerSettings('ollama')['embedding_dimensions']);
  }

  /**
   * Unbekannter Provider, unbekannte Operation, fehlendes Modell.
   */
  public function testInvalidValuesThrow(): void {
    $settings = self::SETTINGS;
    $settings['provider_chat'] = 'openai';
    $settings['providers']['ollama']['embedding_model'] = '';
    $resolver = $this->resolver($settings);
    foreach ([
      [fn() => $resolver->providerId('chat'), \UnexpectedValueException::class, 'Unknown provider "openai" for chat'],
      [fn() => $resolver->providerId('moderation'), \InvalidArgumentException::class, 'Unknown operation'],
      [
        fn() => $resolver->embeddings(),
        \UnexpectedValueException::class,
        'No embeddings model configured for provider ollama',
      ],
    ] as [$call, $class, $message]) {
      try {
        $call();
        $this->fail('Expected ' . $class);
      }
      catch (\Exception $e) {
        $this->assertInstanceOf($class, $e);
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
    $this->assertSame([], $this->created, 'No provider instance on invalid settings.');
  }

}
