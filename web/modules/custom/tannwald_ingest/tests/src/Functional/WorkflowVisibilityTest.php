<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Functional;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker;
use Drupal\tannwald_ingest\Plugin\QueueWorker\SummarizeQueueWorker;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tannwald_ingest\Traits\SummaryFixturesTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * TC-10: zurückgewiesene Zusammenfassungen werden nie Wissen.
 *
 * Weder keyword() noch similar() noch die Dokumentseite zeigen sie; die
 * freigegebene schon. Route per Formular (Reject über formaction) und per JSON
 * (Approve mit bearbeitetem Text); kunde erhält 403.
 */
#[Group('tannwald_ingest')]
class WorkflowVisibilityTest extends BrowserTestBase {

  use SummaryFixturesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node', 'taxonomy', 'file', 'options', 'datetime', 'text', 'filter', 'field', 'workflows',
    'content_moderation', 'language', 'key', 'ai', 'views', 'tannwald_access', 'tannwald_ingest',
    'tannwald_ingest_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Redaktion (alle Transitionen).
   */
  protected UserInterface $editor;

  /**
   * Kunde ohne Transition-Rechte.
   */
  protected UserInterface $customer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if ($this->container->get('database')->driver() !== 'mysql') {
      $this->markTestSkipped('FULLTEXT needs MariaDB/MySQL (SIMPLETEST_DB).');
    }
    $this->createSummaryModel();
    // Die View hängt an Feldern und Rollen: optionale Konfiguration nachziehen.
    $this->container->get('config.installer')->installOptionalConfig();
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('view')->load('redaktion_freigaben'));
    $this->container->get('router.builder')->rebuild();
    $this->editor = $this->drupalCreateUser([], 'lea');
    $this->editor->addRole('redaktion');
    $this->editor->save();
    $this->customer = $this->drupalCreateUser([], 'anna');
    $this->customer->addRole('kunde');
    $this->customer->save();
  }

  /**
   * Text des einzigen Chunks einer Zusammenfassung (wie im Chunker).
   */
  protected function chunkText(NodeInterface $summary): string {
    return $this->container->get('tannwald_ingest.chunker')->chunk((string) $summary->get('body')->value, (string) $summary->label())[0]['text'];
  }

  /**
   * Nids aller Treffer von keyword() und similar().
   *
   * @return int[]
   *   Nids.
   */
  protected function hitNids(string $query, array $vector, array $keys): array {
    $store = $this->container->get('tannwald_ingest.chunk_store');
    return array_merge(array_column($store->keyword($query, $keys, 20), 'nid'), array_column($store->similar($vector, $keys, 20), 'nid'));
  }

  /**
   * POST an die Route mit JSON und X-CSRF-Token.
   *
   * @return array
   *   [Status, dekodierte Antwort].
   */
  protected function postJson(NodeInterface $summary, string $transition, array $payload): array {
    $client = $this->getHttpClient();
    $cookies = $this->getSessionCookies();
    $token = (string) $client->request('GET', $this->buildUrl('session/token'), ['cookies' => $cookies])->getBody();
    $url = Url::fromRoute('tannwald_ingest.review_transition', ['node' => $summary->id(), 'transition' => $transition]);
    $response = $client->request('POST', $this->buildUrl($url), [
      'cookies' => $cookies,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
      'body' => json_encode($payload),
      'http_errors' => FALSE,
    ]);
    return [$response->getStatusCode(), json_decode((string) $response->getBody(), TRUE)];
  }

  /**
   * Freigegebene sichtbar, zurückgewiesene nirgends; Rechte der Route.
   */
  public function testRejectedSummaryIsNeverVisible(): void {
    $alpha = $this->createPublishedDocument('Anleitung Alpha');
    $beta = $this->createPublishedDocument('Anleitung Beta');
    $this->runQueue(EmbedQueueWorker::QUEUE);
    $this->assertSame(['ok', 'ok'], $this->runQueue(SummarizeQueueWorker::QUEUE));
    $generator = $this->container->get('tannwald_ingest.summary_generator');
    $approved = $generator->findExisting((int) $alpha->id(), (int) $alpha->getRevisionId());
    $rejected = $generator->findExisting((int) $beta->id(), (int) $beta->getRevisionId());
    $this->runQueue(EmbedQueueWorker::QUEUE);
    $keys = $this->container->get('tannwald_access.entitlement')->getRealmsForNode($alpha);
    $this->assertNotEmpty($keys);

    // Zur Prüfung: nirgends sichtbar.
    $this->assertNotContains((int) $approved->id(), $this->hitNids('Zusammenfassung Alpha', self::hashVector($this->chunkText($approved)), $keys));
    $this->drupalLogin($this->editor);
    $this->drupalGet($alpha->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Zusammenfassung von Anleitung Alpha');

    // Zurückweisen per Formular (Button „Reject“ postet per formaction).
    $this->drupalGet('tannwald-test/review/' . $rejected->id());
    $this->assertSession()->buttonExists('Approve');
    $this->submitForm([], 'Reject');
    $this->assertSession()->addressEquals('redaktion/freigaben');
    $this->assertSession()->pageTextContains('The summary has been rejected.');
    $this->assertSession()->pageTextContains('Zurückgewiesen · 1');

    // Freigeben per JSON mit bearbeitetem Text.
    [$status, $json] = $this->postJson($approved, 'freigeben', ['body' => '<p>Freigegebene Fassung Alpha: Kapitel 2 Sicherheit beachten.</p>']);
    $this->assertSame(200, $status, (string) json_encode($json));
    $this->assertSame('published', $json['moderation_state']);
    $this->assertTrue($json['bearbeitet']);
    $this->assertSame((int) $approved->id(), $json['nid']);

    $this->runQueue(EmbedQueueWorker::QUEUE);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $approved = $storage->loadUnchanged($approved->id());
    $rejected = $storage->loadUnchanged($rejected->id());
    $this->assertSame('zurueckgewiesen', $rejected->get('moderation_state')->value);

    // Freigegeben: in keyword und similar, am Dokument.
    $approvedHits = $this->hitNids('Freigegebene', self::hashVector($this->chunkText($approved)), $keys);
    $this->assertContains((int) $approved->id(), $approvedHits);
    $this->drupalGet($alpha->toUrl());
    $this->assertSession()->pageTextContains('Freigegebene Fassung Alpha');

    // Zurückgewiesen: keine Zeile, kein Treffer (auch nicht mit exaktem
    // Vektor), nicht am Dokument.
    $rows = $this->container->get('database')->query('SELECT COUNT(*) FROM {tannwald_chunk} WHERE nid = :nid', [':nid' => $rejected->id()])->fetchField();
    $this->assertSame(0, (int) $rows);
    $this->assertNotContains((int) $rejected->id(), $this->hitNids('Zusammenfassung Beta', self::hashVector($this->chunkText($rejected)), $keys));
    $this->drupalGet($beta->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Zusammenfassung von Anleitung Beta');
    $this->assertNull(tannwald_ingest_summary_for($beta, 'de'));
    $this->assertSame((int) $approved->id(), tannwald_ingest_summary_for($alpha, 'de')['nid']);

    // Kunde: 403 per JSON (gültiger Token) und per Formular-POST ohne Token.
    $this->drupalLogin($this->customer);
    [$status, $json] = $this->postJson($rejected, 'zurueckweisen', []);
    $this->assertSame(403, $status);
    $this->assertArrayHasKey('fehler', $json);
    $response = $this->getHttpClient()->request('POST', $this->buildUrl('redaktion/zusammenfassung/' . $rejected->id() . '/freigeben'), [
      'cookies' => $this->getSessionCookies(),
      'form_params' => ['body' => 'x'],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $this->drupalGet('redaktion/freigaben');
    $this->assertSession()->statusCodeEquals(403);
  }

}
