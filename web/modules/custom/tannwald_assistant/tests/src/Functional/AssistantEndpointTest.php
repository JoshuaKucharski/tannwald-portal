<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Functional;

use Drupal\tannwald_assistant\AnswerService;
use Drupal\tannwald_assistant\Controller\AssistantApiController;
use Drupal\tannwald_assistant\Entity\SupportAnfrage;
use PHPUnit\Framework\Attributes\Group;

/**
 * POST /api/assistant, /feedback, /support: Auth, CSRF, Länge, Flood, Limit.
 */
#[Group('tannwald_assistant')]
final class AssistantEndpointTest extends AssistantFunctionalTestBase {

  /**
   * Anonym 403, ohne CSRF 403, zu lang 400, Flood 429, Limit 503.
   */
  public function testGuards(): void {
    $this->indexManual();
    [$status] = $this->api('POST', 'api/assistant', ['frage' => 'Wie oft?', 'session' => 's'], FALSE);
    $this->assertSame(403, $status, 'Anonym.');

    $account = $this->loginAs('kunde-a');
    [$status] = $this->api('POST', 'api/assistant', ['frage' => 'Wie oft?', 'session' => 's'], FALSE);
    $this->assertSame(403, $status, 'Ohne X-CSRF-Token.');

    [$status, $json] = $this->api('POST', 'api/assistant', ['frage' => str_repeat('a', 1001), 'session' => 's']);
    $this->assertSame(400, $status);
    $this->assertArrayHasKey('fehler', $json);

    $this->config('tannwald_assistant.settings')->set('assistant_enabled', FALSE)->save();
    $question = ['frage' => 'Wie oft muss die Siegelstation gereinigt werden?', 'session' => 's'];
    [$status, $json] = $this->api('POST', 'api/assistant', $question);
    $this->assertSame(503, $status);
    $this->assertSame('limit', $json['status']);
    $this->assertSame('', $json['antwort_html']);
    $this->assertNotEmpty($json['hinweis']);
    $this->config('tannwald_assistant.settings')->set('assistant_enabled', TRUE)->save();

    // Flood: 10 Fragen je Minute und uid; die 11. bekommt 429.
    $flood = $this->container->get('flood');
    $flood->clear(AssistantApiController::FLOOD_ASK, 'uid:' . $account->id());
    for ($i = 0; $i < AssistantApiController::FLOOD_ASK_LIMIT; $i++) {
      $flood->register(AssistantApiController::FLOOD_ASK, AssistantApiController::FLOOD_WINDOW, 'uid:' . $account->id());
    }
    [$status, $json] = $this->api('POST', 'api/assistant', ['frage' => 'Wie oft?', 'session' => 's']);
    $this->assertSame(429, $status);
    $this->assertArrayHasKey('fehler', $json);
    $flood->clear(AssistantApiController::FLOOD_ASK, 'uid:' . $account->id());
  }

  /**
   * Antwort nach Vertrag (inkl. langcode je Quelle), Feedback nur eigenes.
   */
  public function testAnswerAndFeedback(): void {
    $this->indexManual();
    $this->loginAs('kunde-a');
    [$status, $json] = $this->api('POST', 'api/assistant', [
      'frage' => 'Wie oft muss die Siegelstation gereinigt werden?',
      'maschine_nid' => (int) $this->w['m1']->id(),
      'session' => 'browser-uuid-1',
    ]);
    $this->assertSame(200, $status);
    $this->assertSame(['status', 'antwort_html', 'quellen', 'log_id'], array_keys($json));
    $this->assertSame('beantwortet', $json['status']);
    $this->assertSame(['n', 'nid', 'titel', 'version', 'heading_path', 'url', 'langcode'], array_keys($json['quellen'][0]));
    $this->assertSame((int) $this->w['d_ba']->id(), $json['quellen'][0]['nid']);
    $this->assertSame('en', $json['quellen'][0]['langcode']);
    $this->assertStringContainsString('#kapitel-', $json['quellen'][0]['url']);
    $logId = $json['log_id'];
    $this->assertGreaterThan(0, $logId);

    // Fremde Maschine (KT-500 von Kunde B) ist für kunde-a unsichtbar.
    $foreign = ['frage' => 'Wie oft?', 'maschine_nid' => (int) $this->w['m2']->id(), 'session' => 's'];
    [$status] = $this->api('POST', 'api/assistant', $foreign);
    $this->assertSame(404, $status);

    [$status, $json] = $this->api('POST', 'api/assistant/feedback', ['log_id' => $logId, 'bewertung' => -1]);
    $this->assertSame(200, $status);
    $this->assertSame(['ok' => TRUE], $json);
    $row = $this->container->get('database')->select(AnswerService::LOG_TABLE, 'l')
      ->fields('l', ['bewertung', 'maschine_nid'])
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();
    $this->assertEquals(-1, $row['bewertung']);
    $this->assertEquals($this->w['m1']->id(), $row['maschine_nid']);

    [$status] = $this->api('POST', 'api/assistant/feedback', ['log_id' => $logId, 'bewertung' => 5]);
    $this->assertSame(400, $status);

    $this->drupalLogout();
    $this->loginAs('kunde-b');
    [$status] = $this->api('POST', 'api/assistant/feedback', ['log_id' => $logId, 'bewertung' => 1]);
    $this->assertSame(404, $status, 'Fremder Log-Eintrag.');
  }

  /**
   * Support: support_anfrage mit Frage, Verlauf, Seriennummer, Nutzer → 201.
   */
  public function testSupportRequest(): void {
    $this->indexManual();
    $this->container->get('state')->set('tannwald_assistant_test.reply', 'Dazu steht nichts im Kontext.');
    $account = $this->loginAs('kunde-a');
    $first = ['frage' => 'Wie oft muss die Siegelstation gereinigt werden?', 'session' => 'sess-x'];
    $this->api('POST', 'api/assistant', $first);
    [, $json] = $this->api('POST', 'api/assistant', [
      'frage' => 'Wie ist die Lieferzeit für Teil 4711?',
      'maschine_nid' => (int) $this->w['m1']->id(),
      'session' => 'sess-x',
    ]);
    $this->assertSame('abgelehnt', $json['status']);
    $this->assertSame([], $json['quellen']);

    [$status, $support] = $this->api('POST', 'api/assistant/support', ['log_id' => $json['log_id']]);
    $this->assertSame(201, $status);
    $entity = SupportAnfrage::load($support['support_anfrage_id']);
    $this->assertInstanceOf(SupportAnfrage::class, $entity);
    $this->assertSame('Wie ist die Lieferzeit für Teil 4711?', $entity->get('frage')->value);
    $this->assertSame((int) $account->id(), (int) $entity->getOwnerId());
    $this->assertSame((int) $this->w['m1']->id(), $entity->getMachineId());
    $this->assertSame('TB200-2021-0417', $entity->get('maschine_nid')->entity->label());
    $this->assertSame('offen', $entity->get('status')->value);
    $history = $entity->getHistory();
    $this->assertCount(2, $history);
    $this->assertSame(['log_id', 'frage', 'status', 'antwort', 'zeit'], array_keys($history[0]));
    $this->assertSame('Wie oft muss die Siegelstation gereinigt werden?', $history[0]['frage']);
    $this->assertSame('abgelehnt', $history[1]['status']);

    [$status] = $this->api('POST', 'api/assistant/support', ['log_id' => 999999]);
    $this->assertSame(404, $status);
  }

}
