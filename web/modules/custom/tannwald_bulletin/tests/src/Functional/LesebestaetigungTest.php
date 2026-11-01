<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_bulletin\Functional;

use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\Gettext;
use Drupal\node\Entity\Node;
use Drupal\node\NodeAccessRebuild;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tannwald_access\Traits\AccessFixturesTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drupal\workflows\Entity\Workflow;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * TC-05: Freigabe → Mail in Empfängersprache, Bestätigung per Button und JSON.
 */
#[Group('tannwald_bulletin')]
class LesebestaetigungTest extends BrowserTestBase {

  use AccessFixturesTrait;

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
    'language',
    'locale',
    'mailer_transport',
    'symfony_mailer',
    'symfony_mailer_test',
    'tannwald_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Testwelt.
   *
   * @var array<string, \Drupal\Core\Entity\EntityInterface>
   */
  protected array $w;

  /**
   * Konten.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  protected array $users = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContentModel();
    $this->addField('node', 'bulletin', 'field_bestaetigung_pflicht', 'boolean');
    DateFormat::create(['id' => 'tannwald_datum', 'label' => 'Tannwald Datum', 'pattern' => 'd.m.Y'])->save();
    ConfigurableLanguage::createFromLangcode('de')->save();

    // Workflow wie config/sync/workflows.workflow.redaktion.yml (A, 2.1).
    $workflow = Workflow::create(['id' => 'redaktion', 'label' => 'Redaktion', 'type' => 'content_moderation']);
    $plugin = $workflow->getTypePlugin();
    $this->assertInstanceOf(ContentModerationInterface::class, $plugin);
    $plugin->addState('zur_pruefung', 'Zur Prüfung');
    $plugin->addState('zurueckgewiesen', 'Zurückgewiesen');
    $plugin->deleteTransition('create_new_draft');
    $plugin->deleteTransition('publish');
    $allStates = ['draft', 'zur_pruefung', 'zurueckgewiesen', 'published'];
    $plugin->addTransition('entwurf_speichern', 'Entwurf speichern', $allStates, 'draft');
    $plugin->addTransition('freigeben', 'Freigeben', ['zur_pruefung'], 'published');
    $plugin->addTransition('zur_pruefung_senden', 'Zur Prüfung senden', ['draft', 'zurueckgewiesen'], 'zur_pruefung');
    $plugin->addTransition('zurueckweisen', 'Zurückweisen', ['zur_pruefung'], 'zurueckgewiesen');
    $plugin->addEntityTypeAndBundle('node', 'bulletin');
    $workflow->save();
    $role = Role::load('redaktion');
    foreach (['entwurf_speichern', 'freigeben', 'zur_pruefung_senden', 'zurueckweisen'] as $transition) {
      $role->grantPermission("use redaktion transition $transition");
    }
    $role->grantPermission('edit any bulletin content')->grantPermission('view latest version')->save();

    // Die View hängt an den Rollen; darum erst jetzt installieren.
    $this->container->get('module_installer')->install(['tannwald_bulletin', 'tannwald_bulletin_test']);
    $this->rebuildContainer();
    $path = $this->container->get('extension.list.module')->getPath('tannwald_bulletin') . '/translations/de.po';
    Gettext::fileToDatabase((object) ['uri' => $path, 'langcode' => 'de'], []);
    $this->config('tannwald_bulletin.settings')->set('base_url', 'https://portal.example')->save();

    $this->w = $this->createAccessWorld();
    $this->users['kunde-a'] = $this->createUser([], 'kunde-a', FALSE, [
      'roles' => ['kunde'],
      'field_firma' => $this->w['firma_a']->id(),
      'field_land' => 'CH',
      'preferred_langcode' => 'de',
    ]);
    $this->users['kunde-c'] = $this->createUser([], 'kunde-c', FALSE, [
      'roles' => ['kunde'],
      'field_firma' => $this->w['firma_c']->id(),
      'field_land' => 'AR',
      'preferred_langcode' => 'en',
    ]);
    $this->users['lea'] = $this->createUser([], 'lea', FALSE, ['roles' => ['redaktion'], 'field_land' => 'CH']);
    $this->users['marco'] = $this->createUser([], 'marco', FALSE, ['roles' => ['redaktion'], 'field_land' => 'CH']);
    $this->container->get(NodeAccessRebuild::class)->rebuild();
  }

  /**
   * Sicherheits-Bulletin von lea, zur Prüfung gesendet.
   */
  protected function bulletinInReview(): NodeInterface {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($this->users['lea']);
    $node = Node::create([
      'type' => 'bulletin',
      'title' => 'SB-2026-07 Schutzhaube Siegelstation',
      'uid' => $this->users['lea']->id(),
      'moderation_state' => 'draft',
      'field_bulletin_art' => 'sicherheit',
      'field_bestaetigung_pflicht' => 1,
      'field_maschinentyp' => [$this->w['tb200']->id()],
    ]);
    $node->save();
    $node->set('moderation_state', 'zur_pruefung')->save();
    $switcher->switchBack();
    return $node;
  }

  /**
   * Arbeitet die Queue tannwald_notify im Testprozess ab.
   */
  protected function runNotifyQueue(): int {
    $queue = $this->container->get('queue')->get('tannwald_notify');
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('tannwald_notify');
    $processed = 0;
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
      $processed++;
    }
    return $processed;
  }

  /**
   * Gesammelte Mails nach Empfängeradresse.
   *
   * @return array<string, \Symfony\Component\Mime\Email>
   *   Mails.
   */
  protected function capturedMails(): array {
    $mails = [];
    foreach ($this->container->get('keyvalue')->get('symfony_mailer_test')->get('emails', []) as $mail) {
      $mails[$mail->getTo()[0]->getAddress()] = $mail;
    }
    return $mails;
  }

  /**
   * POST an /bulletin/{nid}/bestaetigen mit der Browser-Sitzung.
   *
   * @param int $nid
   *   Bulletin.
   * @param array<string, mixed> $payload
   *   JSON-Körper.
   * @param string|null $token
   *   X-CSRF-Token oder NULL (ohne Header).
   */
  protected function postJson(int $nid, array $payload, ?string $token): ResponseInterface {
    $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);
    $jar = CookieJar::fromArray([$this->getSessionName() => $this->getSession()->getCookie($this->getSessionName())], $host);
    $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
    if ($token !== NULL) {
      $headers['X-CSRF-Token'] = $token;
    }
    return $this->getHttpClient()->request('POST', $this->buildUrl("bulletin/$nid/bestaetigen"), [
      'cookies' => $jar,
      'headers' => $headers,
      'body' => json_encode($payload),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Zeilen in lesebestaetigung für ein Bulletin.
   */
  protected function confirmationCount(int $nid): int {
    return (int) $this->container->get('database')->select('lesebestaetigung')
      ->condition('bulletin_nid', $nid)
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Audit-Zeilen eines Nutzers.
   *
   * @return array<int, array<string, mixed>>
   *   Zeilen mit entity_type, entity_id, details (dekodiert).
   */
  protected function auditRows(string $ereignis, UserInterface $account): array {
    $rows = $this->container->get('database')->select('tannwald_audit', 'a')
      ->fields('a', ['entity_type', 'entity_id', 'details'])
      ->condition('ereignis', $ereignis)
      ->condition('uid', $account->id())
      ->orderBy('id')
      ->execute()->fetchAll(FetchAs::Associative);
    foreach ($rows as &$row) {
      $row['details'] = json_decode((string) $row['details'], TRUE);
    }
    return $rows;
  }

  /**
   * Freigabe durch die zweite Redaktorin → Mails; Erstellerin wird abgewiesen.
   */
  public function testApprovalSendsMailsInRecipientLanguage(): void {
    $bulletin = $this->bulletinInReview();
    $nid = (int) $bulletin->id();

    // Vier-Augen-Prinzip im Bearbeitungsformular.
    $this->drupalLogin($this->users['lea']);
    $this->drupalGet("node/$nid/edit");
    $this->submitForm(['moderation_state[0][state]' => 'published'], 'Save');
    $this->assertSession()->pageTextContains('Safety bulletins must not be approved by the person who created them (four-eyes principle).');
    $this->assertSame(0, $this->container->get('queue')->get('tannwald_notify')->numberOfItems());

    $this->drupalLogin($this->users['marco']);
    $this->drupalGet("node/$nid/edit");
    $this->submitForm(['moderation_state[0][state]' => 'published'], 'Save');
    $this->assertSession()->pageTextNotContains('four-eyes');
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$nid]);
    $this->assertTrue(Node::load($nid)->isPublished());

    $this->assertSame(1, $this->runNotifyQueue());
    $mails = $this->capturedMails();
    $this->assertSame(['kunde-a@example.com', 'kunde-c@example.com'], array_keys($mails));

    $german = $mails['kunde-a@example.com'];
    $this->assertSame('Sicherheits-Bulletin SB-2026-07: Schutzhaube Siegelstation — Lesebestätigung erforderlich', $german->getSubject());
    $html = (string) $german->getHtmlBody();
    $this->assertStringContainsString('TB200-2021-0417', $html);
    $this->assertStringContainsString('https://portal.example/de/node/' . $nid, $html);
    $this->assertStringContainsString('Bulletin lesen', $html);

    $english = $mails['kunde-c@example.com'];
    $this->assertSame('Safety bulletin SB-2026-07: Schutzhaube Siegelstation — read confirmation required', $english->getSubject());
    $html = (string) $english->getHtmlBody();
    $this->assertStringContainsString('TB200-2023-0099', $html);
    $this->assertStringContainsString('https://portal.example/node/' . $nid, $html);
    $this->assertStringNotContainsString('TB200-2021-0417', $html, 'Nur eigene Maschinen.');

    // Ein zweites Speichern ohne Übergang erzeugt keinen neuen Job.
    $this->drupalGet("node/$nid/edit");
    $this->submitForm(['title[0][value]' => 'SB-2026-07 Schutzhaube Siegelstation (Korrektur)'], 'Save');
    $this->assertSame(0, $this->container->get('queue')->get('tannwald_notify')->numberOfItems());
  }

  /**
   * Bestätigen per Button (Form-Token) und JSON (X-CSRF-Token), Fehler, View.
   */
  public function testConfirmByButtonAndJson(): void {
    $bulletin = $this->createAccessNode('bulletin', 'SB-2026-07 Schutzhaube Siegelstation', [
      'field_bulletin_art' => 'sicherheit',
      'field_bestaetigung_pflicht' => 1,
      'field_maschinentyp' => [$this->w['tb200']],
      'moderation_state' => 'published',
    ]);
    $nid = (int) $bulletin->id();
    $m1 = (int) $this->w['m1']->id();
    $customer = $this->users['kunde-a'];

    // Button-Weg.
    $this->drupalLogin($customer);
    $this->drupalGet("tannwald-bulletin-test/$nid/$m1");
    $this->assertSession()->pageTextContains('banner id=SB-2026-07 titel=Schutzhaube Siegelstation art=sicherheit pflicht=ja bestaetigt_am=none');
    $this->submitForm([], 'Confirm as read');
    $this->assertSession()->addressEquals("tannwald-bulletin-test/$nid/$m1");
    $this->assertSession()->pageTextContains('Read confirmation saved.');
    $this->assertSession()->pageTextMatches('/bestaetigt_am=\d{2}\.\d{2}\.\d{4} durch=' . $customer->id() . ' /');
    $this->assertSession()->pageTextContains('no form');
    $this->assertSame(1, $this->confirmationCount($nid));
    $audit = $this->auditRows('lesebestaetigung', $customer);
    $this->assertCount(1, $audit);
    $this->assertSame(['bulletin_nid' => $nid, 'maschine_nid' => $m1], $audit[0]['details']);

    // JSON-Weg: zweite Bestätigung derselben Maschine ändert nichts.
    $token = $this->drupalGet('session/token');
    $response = $this->postJson($nid, ['maschine' => $m1], $token);
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame($m1, $body['maschine_nid']);
    $this->assertTrue($body['bereits_bestaetigt']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $body['bestaetigt_am']);
    $this->assertSame(1, $this->confirmationCount($nid));
    $this->assertCount(1, $this->auditRows('lesebestaetigung', $customer), 'Kein zweiter Audit-Eintrag.');

    // JSON-Weg für ein zweites Bulletin: neu, dann bereits bestätigt.
    $second = $this->createAccessNode('bulletin', 'SB-2026-08 Heizpatronen Siegelbacken', [
      'field_bulletin_art' => 'sicherheit',
      'field_maschinentyp' => [$this->w['tb200']],
      'moderation_state' => 'published',
    ]);
    $first = json_decode((string) $this->postJson((int) $second->id(), ['maschine' => $m1], $token)->getBody(), TRUE);
    $this->assertFalse($first['bereits_bestaetigt']);
    $again = json_decode((string) $this->postJson((int) $second->id(), ['maschine' => $m1], $token)->getBody(), TRUE);
    $this->assertTrue($again['bereits_bestaetigt']);
    $this->assertSame($first['bestaetigt_am'], $again['bestaetigt_am']);
    $this->assertSame(1, $this->confirmationCount((int) $second->id()));

    // Ohne Token und mit fremder Maschine (M3 gehört Firma C): 403 und Audit.
    $this->assertSame(403, $this->postJson($nid, ['maschine' => $m1], NULL)->getStatusCode());
    $this->assertSame(403, $this->postJson($nid, ['maschine' => (int) $this->w['m3']->id()], $token)->getStatusCode());
    $denied = array_map(static fn(array $row): string => $row['details']['route'], $this->auditRows('zugriff_verweigert', $customer));
    $this->assertSame(['tannwald_bulletin.confirm', 'tannwald_bulletin.confirm'], $denied);
    $this->assertSame(1, $this->confirmationCount($nid));

    // View der Redaktion: bestätigt und offen.
    $this->drupalGet("redaktion/bulletins/$nid/bestaetigungen");
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->users['marco']);
    $this->drupalGet("redaktion/bulletins/$nid/bestaetigungen");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Lesebestätigungen');
    $this->assertSession()->elementTextContains('css', 'table', 'kunde-a');
    $this->assertSession()->elementTextContains('css', 'table', 'Kunde A');
    $this->assertSession()->elementTextContains('css', 'table', 'TB200-2021-0417');
    $this->assertSession()->pageTextContains('Open: 1');
    $this->assertSession()->pageTextContains('kunde-c: TB200-2023-0099');
  }

}
