<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Provider;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Liefert Provider-Plugin und Modell je Operation (Vertrag 3.1 an 3.2/4.x).
 *
 * Führend ist tannwald_assistant.settings: provider_chat und
 * provider_embeddings (je ollama|dashscope), Modelle, Dimension und Kosten
 * unter providers.<id>. ai.settings default_providers wird nicht gelesen.
 * Jeder resolve()-Aufruf erzeugt eine frische Plugin-Instanz, damit keine
 * Payload-Konfiguration (temperature, dimensions) zwischen Operationen reist.
 */
final class ProviderResolver {

  /**
   * Erlaubte Provider (Plugin-IDs des AI-Moduls).
   */
  public const PROVIDERS = ['ollama', 'dashscope'];

  /**
   * Operation → Settings-Schlüssel des Providers.
   */
  public const SETTING_KEYS = [
    'chat' => 'provider_chat',
    'embeddings' => 'provider_embeddings',
  ];

  /**
   * Timeouts in Sekunden für API-Provider.
   *
   * Ollama behält ai.settings request_timeout (60 s, Kaltstart des Modells).
   */
  public const TIMEOUTS = [
    'dashscope' => ['chat' => 30, 'embeddings' => 20],
  ];

  /**
   * Provider, denen `dimensions` als Payload mitgegeben wird.
   */
  public const SENDS_DIMENSIONS = ['dashscope'];

  /**
   * Vorgabe, solange providers.<id>.embedding_dimensions fehlt.
   */
  public const DEFAULT_DIMENSIONS = 1024;

  /**
   * Prozesslokale Überschreibung je Operation, nie gespeichert.
   *
   * @var array<string, array{0: string, 1: string|null}>
   */
  private array $overrides = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PluginManagerInterface $aiProvider,
  ) {}

  /**
   * Provider-ID für chat|embeddings.
   *
   * @throws \InvalidArgumentException
   *   Unbekannte Operation.
   * @throws \UnexpectedValueException
   *   Provider nicht in PROVIDERS.
   */
  public function providerId(string $operation): string {
    self::assertOperation($operation);
    if (isset($this->overrides[$operation])) {
      return $this->overrides[$operation][0];
    }
    $settings = $this->settings();
    // Vor update 10006 (flache Settings): der eine Schlüssel provider.
    $id = (string) ($settings->get(self::SETTING_KEYS[$operation]) ?? $settings->get('provider') ?? '');
    if (!in_array($id, self::PROVIDERS, TRUE)) {
      throw new \UnexpectedValueException(sprintf('Unknown provider "%s" for %s (allowed: %s).', $id, $operation, implode('|', self::PROVIDERS)));
    }
    return $id;
  }

  /**
   * Einstellungen eines Providers mit Typen und Vorgaben.
   *
   * @return array{chat_model: string, embedding_model: string, embedding_dimensions: int, kosten_pro_1k_chf: float}
   *   Werte aus providers.<id>.
   */
  public function providerSettings(string $providerId): array {
    $settings = $this->settings();
    $values = (array) ($settings->get('providers.' . $providerId) ?? []);
    if ($values === [] && $settings->get('providers') === NULL && $settings->get('provider') === $providerId) {
      // Vor update 10006: flache Schlüssel gelten für den einen Provider.
      $values = [
        'chat_model' => $settings->get('chat_model'),
        'embedding_model' => $settings->get('embedding_model'),
        'kosten_pro_1k_chf' => $settings->get('kosten_pro_1k_chf'),
      ];
    }
    return [
      'chat_model' => (string) ($values['chat_model'] ?? ''),
      'embedding_model' => (string) ($values['embedding_model'] ?? ''),
      'embedding_dimensions' => (int) ($values['embedding_dimensions'] ?? self::DEFAULT_DIMENSIONS),
      'kosten_pro_1k_chf' => (float) ($values['kosten_pro_1k_chf'] ?? 0.0),
    ];
  }

  /**
   * Rohname des Modells für eine Operation.
   *
   * @throws \UnexpectedValueException
   *   Kein Modell konfiguriert.
   */
  public function model(string $operation, ?string $providerId = NULL): string {
    $providerId ??= $this->providerId($operation);
    self::assertOperation($operation);
    $settings = $this->providerSettings($providerId);
    $model = $operation === 'chat' ? $settings['chat_model'] : $settings['embedding_model'];
    [$overrideProvider, $overrideModel] = $this->overrides[$operation] ?? [NULL, NULL];
    if ($overrideProvider === $providerId && $overrideModel !== NULL) {
      $model = $overrideModel;
    }
    if ($model === '') {
      throw new \UnexpectedValueException(sprintf('No %s model configured for provider %s.', $operation, $providerId));
    }
    return $model;
  }

  /**
   * Kosten je 1'000 Chat-Tokens in CHF (0.0 für unbekannte Provider).
   */
  public function costPer1k(string $providerId): float {
    return in_array($providerId, self::PROVIDERS, TRUE) ? $this->providerSettings($providerId)['kosten_pro_1k_chf'] : 0.0;
  }

  /**
   * Plugin-Instanz, Modell und Dimension für eine Operation.
   *
   * @param string $operation
   *   Die Operation: chat oder embeddings.
   * @param string|null $providerId
   *   Anderer Provider als der konfigurierte (Belege, Eval), sonst NULL.
   */
  public function resolve(string $operation, ?string $providerId = NULL): ResolvedProvider {
    self::assertOperation($operation);
    $providerId ??= $this->providerId($operation);
    $settings = $this->providerSettings($providerId);
    $model = $this->model($operation, $providerId);
    $timeout = self::TIMEOUTS[$providerId][$operation] ?? NULL;
    $provider = $timeout === NULL
      ? $this->aiProvider->createInstance($providerId)
      : $this->aiProvider->createInstance($providerId, ['http_client_options' => ['timeout' => $timeout]]);
    if ($operation === 'embeddings' && in_array($providerId, self::SENDS_DIMENSIONS, TRUE)) {
      $provider->setConfiguration(['dimensions' => $settings['embedding_dimensions']]);
    }
    return new ResolvedProvider($providerId, $operation, $model, $provider, $settings['embedding_dimensions'], $settings['kosten_pro_1k_chf']);
  }

  /**
   * Überschreibt Provider (und Modell) einer Operation nur für diesen Prozess.
   *
   * Für Eval-Läufe gegen einen anderen Provider, ohne die geteilte
   * Konfiguration zu ändern (`tannwald:eval --chat-provider`). Nie gespeichert.
   *
   * @param string $operation
   *   Die Operation: chat oder embeddings.
   * @param string $providerId
   *   Provider aus PROVIDERS.
   * @param string|null $model
   *   Rohname des Modells; NULL = Modell aus providers.<id>.
   */
  public function overrideForProcess(string $operation, string $providerId, ?string $model = NULL): void {
    self::assertOperation($operation);
    if (!in_array($providerId, self::PROVIDERS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown provider "%s" (allowed: %s).', $providerId, implode('|', self::PROVIDERS)));
    }
    $this->overrides[$operation] = [$providerId, $model !== NULL && $model !== '' ? $model : NULL];
  }

  /**
   * Kurzform für resolve('chat').
   */
  public function chat(): ResolvedProvider {
    return $this->resolve('chat');
  }

  /**
   * Kurzform für resolve('embeddings').
   */
  public function embeddings(): ResolvedProvider {
    return $this->resolve('embeddings');
  }

  /**
   * Ob ein Provider nutzbar ist (DashScope: Endpoint und Schlüssel gesetzt).
   */
  public function isUsable(string $providerId, ?string $operation = NULL): bool {
    if (!in_array($providerId, self::PROVIDERS, TRUE) || !$this->aiProvider->hasDefinition($providerId)) {
      return FALSE;
    }
    try {
      return (bool) $this->aiProvider->createInstance($providerId)->isUsable($operation);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Kopf für Eval-Berichte und Formulare.
   *
   * @return array{provider: string, chat_model: string, embedding_model: string}
   *   Provider (bei Abweichung je Operation benannt) und Modelle.
   */
  public function summary(): array {
    $chat = $this->providerId('chat');
    $embeddings = $this->providerId('embeddings');
    return [
      'provider' => $chat === $embeddings ? $chat : sprintf('%s (chat), %s (embeddings)', $chat, $embeddings),
      'chat_model' => $this->model('chat', $chat),
      'embedding_model' => $this->model('embeddings', $embeddings),
    ];
  }

  /**
   * Die Settings (mit Overrides).
   */
  private function settings(): ImmutableConfig {
    return $this->configFactory->get('tannwald_assistant.settings');
  }

  /**
   * Prüft die Operation.
   */
  private static function assertOperation(string $operation): void {
    if (!isset(self::SETTING_KEYS[$operation])) {
      throw new \InvalidArgumentException(sprintf('Unknown operation "%s" (allowed: chat|embeddings).', $operation));
    }
  }

}
