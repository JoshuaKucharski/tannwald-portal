<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Functional;

use Drupal\tannwald_assistant\AnswerService;
use Drupal\tannwald_assistant\Entity\SupportAnfrage;
use PHPUnit\Framework\Attributes\Group;

/**
 * Views: Unbeantwortete Fragen, Nutzung, Kosten, Support-Anfragen.
 */
#[Group('tannwald_assistant')]
final class AssistantViewsTest extends AssistantFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'taxonomy',
    'file',
    'options',
    'workflows',
    'content_moderation',
    'views',
    'tannwald_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Die Rollen entstehen erst mit der Testwelt: optionale Views jetzt.
    $this->container->get('config.installer')->installOptionalConfig();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Schreibt eine Log-Zeile.
   */
  private function log(string $frage, string $status, ?int $bewertung, int $ageDays = 0): void {
    $this->container->get('database')->insert(AnswerService::LOG_TABLE)->fields([
      'session_hash' => str_repeat('a', 64),
      'uid_hash' => str_repeat('b', 64),
      'langcode' => 'de',
      'frage' => $frage,
      'frage_normalisiert' => AnswerService::normalizeQuestion($frage),
      'antwort' => '',
      'quellen' => '[]',
      'status' => $status,
      'bewertung' => $bewertung,
      'dauer_ms' => 1200,
      'injection_verdacht' => 0,
      'maschine_nid' => (int) $this->w['m1']->id(),
      'created' => \Drupal::time()->getRequestTime() - $ageDays * 86400,
    ])->execute();
  }

  /**
   * Schreibt eine Usage-Zeile (Tabelle von tannwald_ingest).
   */
  private function usage(string $kontext, string $operation, int $prompt, int $completion): void {
    $this->container->get('database')->insert('tannwald_usage')->fields([
      'modell' => 'qwen2.5:7b',
      'operation' => $operation,
      'prompt_tokens' => $prompt,
      'completion_tokens' => $completion,
      'kosten_chf' => 0.25,
      'kontext' => $kontext,
      'created' => \Drupal::time()->getRequestTime(),
    ])->execute();
  }

  /**
   * Inhalte, Gruppierung, Summen und Rollen der vier Views.
   */
  public function testViews(): void {
    $this->log('Wie ist die Lieferzeit für Teil 4711?', 'abgelehnt', NULL);
    $this->log('wie ist die  Lieferzeit für Teil 4711', 'abgelehnt', NULL);
    $this->log('Wie ist die Lieferzeit für Teil 4711?', 'abgelehnt', NULL);
    $this->log('Welche Folienstärke ist zugelassen?', 'beantwortet', -1);
    $this->log('Wie oft reinigen?', 'beantwortet', NULL);
    $this->log('Alte Frage aus dem Vormonat?', 'abgelehnt', NULL, 40);
    $this->usage('assistent', 'chat', 1000, 100);
    $this->usage('assistent', 'chat', 500, 50);
    $this->usage('ingest', 'embedding', 200, 0);
    $this->config('tannwald_assistant.settings')->set('monthly_token_limit', 10000)->save();
    SupportAnfrage::create([
      'uid' => $this->users['kunde-a']->id(),
      'maschine_nid' => $this->w['m1']->id(),
      'frage' => 'Wie ist die Lieferzeit für Teil 4711?',
      'verlauf' => '[]',
    ])->save();

    $paths = [
      'redaktion/unbeantwortet',
      'admin/tannwald/nutzung',
      'admin/tannwald/kosten',
      'redaktion/support-anfragen',
    ];
    foreach ($paths as $path) {
      $this->drupalLogin($this->users['kunde-a']);
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
      $this->drupalLogout();
    }

    $redaktion = $this->createUser([], 'lea', FALSE, ['roles' => ['redaktion']]);
    $this->drupalLogin($redaktion);
    $this->drupalGet('redaktion/unbeantwortet');
    $this->assertSession()->statusCodeEquals(200);
    // Angezeigt wird die normalisierte Frage mit grossem Anfangsbuchstaben.
    $row = '//tr[td[contains(., "Wie ist die lieferzeit für teil 4711")]]';
    $this->assertSession()->elementsCount('xpath', $row, 1);
    $this->assertSession()->elementTextContains('xpath', $row . '/td[2]', '3');
    $this->assertSession()->elementTextContains('xpath', $row . '/td[3]', 'TB-200');
    $this->assertSession()->elementTextContains('xpath', '//tr[td[contains(., "Welche folienstärke")]]/td[2]', '1');
    $this->assertSession()->pageTextNotContains('Wie oft reinigen');
    $this->assertSession()->pageTextNotContains('Alte Frage');
    $this->assertSession()->linkExists('Dokument anlegen');
    $this->assertSession()->linkExists('An Support');

    $this->drupalGet('redaktion/support-anfragen');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Wie ist die Lieferzeit für Teil 4711?');
    $this->assertSession()->pageTextContains('TB200-2021-0417');
    $this->assertSession()->pageTextContains('kunde-a');
    $this->drupalLogout();

    $admin = $this->createUser([], 'pa', FALSE, ['roles' => ['portal_admin']]);
    $this->drupalLogin($admin);
    $this->drupalGet('admin/tannwald/nutzung');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('xpath', '//tr[td[contains(., "assistent")]]', '1’500');
    $this->assertSession()->elementTextContains('xpath', '//tr[td[contains(., "assistent")]]', '2');
    $this->assertSession()->pageTextContains('Assistant answers per day');

    $this->drupalGet('admin/tannwald/kosten');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('1’850');
    $this->assertSession()->pageTextContains('18.5 %');
    $this->assertSession()->pageTextContains('0.75');
  }

}
