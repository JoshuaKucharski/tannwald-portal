<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\tannwald_assistant\Doubles\TestClock;
use Drupal\tannwald_assistant\Limit\LimitService;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * LimitService: 80 % → genau eine Mail je Monat, 100 % → search_only.
 */
#[Group('tannwald_assistant')]
final class LimitServiceTest extends AssistantKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'mailer_transport',
    'symfony_mailer',
    'symfony_mailer_test',
    'tannwald_assistant',
  ];

  /**
   * Verstellbare Uhr.
   */
  private TestClock $clock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['symfony_mailer']);
    $this->config('system.site')->set('name', 'myTannwald')->set('mail', 'portal@tannwald.example')->save();
    $this->config('system.date')->set('timezone.default', 'Europe/Zurich')->save();
    // Schema der Usage-Tabelle aus D's Modul (Besitzer tannwald_ingest).
    require_once DRUPAL_ROOT . '/modules/custom/tannwald_ingest/tannwald_ingest.install';
    $this->container->get('database')->schema()->createTable('tannwald_usage', tannwald_ingest_schema()['tannwald_usage']);

    // Start bei der echten Request-Zeit (der Cache vergleicht mit ihr).
    $this->clock = new TestClock((int) $this->container->get('datetime.time')->getRequestTime());
    $this->container->set('datetime.time', $this->clock);
    $this->container->set('tannwald_assistant.limit_check', NULL);
    foreach (['portal_admin', 'redaktion'] as $role) {
      Role::create(['id' => $role, 'label' => $role])->save();
    }
    $this->createUser([], 'admin-aktiv', FALSE, ['roles' => ['portal_admin'], 'mail' => 'admin@example.com']);
    $this->createUser([], 'admin-gesperrt', FALSE, ['roles' => ['portal_admin'], 'status' => 0]);
    $this->createUser([], 'lea', FALSE, ['roles' => ['redaktion']]);
    $this->config('tannwald_assistant.settings')->set('monthly_token_limit', 10000)->save();
  }

  /**
   * Uhrzeit des Tests in der Zeitzone der Site.
   */
  private function zurich(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->clock->getRequestTime()))->setTimezone(new \DateTimeZone('Europe/Zurich'));
  }

  /**
   * Der echte Dienst.
   */
  private function limit(): LimitService {
    return $this->container->get('tannwald_assistant.limit_check');
  }

  /**
   * Schreibt eine Usage-Zeile zur aktuellen Uhrzeit und leert den Cache.
   */
  private function use(int $prompt, int $completion, string $kontext = 'assistent'): void {
    $this->container->get('database')->insert('tannwald_usage')->fields([
      'modell' => 'qwen2.5:7b',
      'operation' => 'chat',
      'prompt_tokens' => $prompt,
      'completion_tokens' => $completion,
      'kosten_chf' => 0,
      'kontext' => $kontext,
      'created' => $this->clock->getRequestTime(),
    ])->execute();
    Cache::invalidateTags([LimitService::CACHE_TAG]);
  }

  /**
   * Versandte Mails (symfony_mailer_test).
   *
   * @return \Symfony\Component\Mime\Email[]
   *   Mails.
   */
  private function mails(): array {
    return $this->container->get('keyvalue')->get('symfony_mailer_test')->get('emails', []);
  }

  /**
   * Ab 80 % eine Mail an aktive portal_admin, ab 100 % search_only.
   */
  public function testWarningOncePerMonthAndSearchOnly(): void {
    $this->use(6000, 1000);
    $this->assertTrue($this->limit()->allowsAnswer($this->createUser([], 'k1')));
    $this->assertCount(0, $this->mails(), '70 %: keine Mail.');

    // Alle Kontexte zählen (Entscheid), auch eval.
    $this->use(1200, 300, 'eval');
    $this->assertTrue($this->limit()->allowsAnswer($this->createUser([], 'k2')));
    $mails = $this->mails();
    $this->assertCount(1, $mails, '85 %: genau eine Mail (nur aktive portal_admin).');
    $this->assertSame('admin@example.com', $mails[0]->getTo()[0]->getAddress());
    $this->assertSame('Assistant: 80 % of the monthly limit reached', $mails[0]->getSubject());
    $this->assertStringContainsString("8'500 of 10'000 tokens", html_entity_decode((string) $mails[0]->getHtmlBody(), ENT_QUOTES));
    $this->assertNotEmpty($this->container->get('state')->get(LimitService::STATE_PREFIX . $this->zurich()->format('Y-m')));
    $this->assertNull($this->limit()->noticeForUi());

    $this->assertTrue($this->limit()->allowsAnswer($this->createUser([], 'k3')));
    $this->assertCount(1, $this->mails(), 'Zweiter Aufruf im selben Monat: keine weitere Mail.');

    $this->use(1500, 0);
    $this->assertTrue($this->limit()->isSearchOnly());
    $this->assertFalse($this->limit()->allowsAnswer($this->createUser([], 'k4')));
    $this->assertSame('The monthly limit of the assistant has been reached. Search remains available.', $this->limit()->noticeForUi());
    $this->assertCount(1, $this->mails());
  }

  /**
   * Neuer Monat: Zähler und Warnung beginnen neu.
   */
  public function testNewMonthStartsOver(): void {
    $this->use(9000, 1000);
    $this->assertFalse($this->limit()->allowsAnswer($this->createUser([], 'k5')));
    $this->assertCount(1, $this->mails());

    // Zweiter Tag des Folgemonats: alte Zeilen zählen nicht mehr.
    $next = $this->zurich()->modify('first day of next month')->setTime(12, 0)->modify('+1 day');
    $this->clock->now = $next->getTimestamp();
    Cache::invalidateTags([LimitService::CACHE_TAG]);
    $this->assertSame($next->format('Y-m'), $this->limit()->monthKey());
    $this->assertSame(0, $this->limit()->monthUsage());
    $this->assertTrue($this->limit()->allowsAnswer($this->createUser([], 'k6')));
    $this->use(8000, 100);
    $this->assertTrue($this->limit()->allowsAnswer($this->createUser([], 'k7')));
    $this->assertCount(2, $this->mails(), 'Im neuen Monat eine neue Warnung.');
  }

  /**
   * Limit 0 = unbegrenzt; Cache 60 s ohne Invalidierung.
   */
  public function testUnlimitedAndCache(): void {
    $this->config('tannwald_assistant.settings')->set('monthly_token_limit', 0)->save();
    $this->use(500000, 0);
    $this->assertFalse($this->limit()->isSearchOnly());
    $this->assertFalse($this->limit()->checkWarning());
    $this->assertSame(0.0, $this->limit()->share());

    $this->config('tannwald_assistant.settings')->set('monthly_token_limit', 1000000)->save();
    $before = $this->limit()->monthUsage();
    $this->container->get('database')->insert('tannwald_usage')->fields([
      'modell' => 'm',
      'operation' => 'chat',
      'prompt_tokens' => 1,
      'completion_tokens' => 0,
      'kosten_chf' => 0,
      'kontext' => 'assistent',
      'created' => $this->clock->getRequestTime(),
    ])->execute();
    $this->assertSame($before, $this->limit()->monthUsage(), 'Gecacht bis zur Invalidierung.');
    Cache::invalidateTags([LimitService::CACHE_TAG]);
    $this->assertSame($before + 1, $this->limit()->monthUsage());
  }

}
