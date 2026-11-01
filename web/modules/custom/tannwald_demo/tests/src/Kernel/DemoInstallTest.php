<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_demo\Kernel;

use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\tannwald_demo\DemoInstaller;
use Drupal\tannwald_demo\DisplayNames;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Seeder tannwald:demo-install / demo-reset an den echten Fixtures.
 *
 * Das Content-Modell kommt aus den echten Dateien in config/sync (A's
 * Konfiguration), damit der Test bricht, wenn Seeder und Modell
 * auseinanderlaufen. Der AI-Provider ist gemockt (nie Ollama).
 */
#[Group('tannwald_demo')]
class DemoInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'options', 'file', 'datetime', 'node', 'taxonomy', 'language',
    'content_translation', 'workflows', 'content_moderation', 'views', 'mailer_transport', 'symfony_mailer', 'key', 'ai',
    'migrate', 'migrate_plus', 'migrate_tools', 'tannwald_access', 'tannwald_ingest', 'tannwald_bulletin', 'tannwald_demo',
  ];

  /**
   * Konfiguration aus config/sync, in Abhängigkeits-Reihenfolge.
   */
  protected const CONFIG_PATTERNS = [
    'language.entity.de', 'language.entity.fr',
    'node.type.*', 'taxonomy.vocabulary.maschinentyp', 'taxonomy.vocabulary.dokumenttyp', 'taxonomy.vocabulary.firma',
    'field.storage.node.*', 'field.storage.taxonomy_term.*', 'field.storage.user.field_firma', 'field.storage.user.field_land',
    'field.field.node.*', 'field.field.taxonomy_term.*', 'field.field.user.user.field_firma', 'field.field.user.user.field_land',
    'core.base_field_override.node.*', 'language.content_settings.node.*', 'filter.format.dokument_html',
    'workflows.workflow.redaktion', 'user.role.kunde', 'user.role.haendler', 'user.role.techniker', 'user.role.redaktion',
    'user.role.compliance', 'user.role.portal_admin',
  ];

  /**
   * Erwartete Zählungen nach demo-install (Fixtures 2.2).
   */
  protected const EXPECTED = [
    'firmen' => 7,
    'maschinen' => 10,
    'users' => 6,
    'dokumente' => 20,
    'revisionen' => 22,
    'revision_translations' => 31,
    'uebersetzungen' => 9,
    'bulletins' => 3,
    'lesebestaetigungen' => 1,
  ];

  /**
   * Der Seeder.
   */
  protected DemoInstaller $installer;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem() {
    parent::setUpFilesystem();
    mkdir($this->siteDirectory . '/private', 0775);
    $settings = Settings::getAll();
    $settings['file_private_path'] = $this->siteDirectory . '/private';
    $settings['tannwald_demo_fixtures_path'] = dirname(DRUPAL_ROOT) . '/fixtures';
    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if ($this->container->get('database')->driver() !== 'mysql') {
      $this->markTestSkipped('FULLTEXT needs MariaDB/MySQL (SIMPLETEST_DB).');
    }
    foreach (['user', 'node', 'taxonomy_term', 'file', 'content_moderation_state', 'lesebestaetigung'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('tannwald_access', ['tannwald_audit']);
    $this->installSchema('tannwald_ingest', ['tannwald_chunk', 'tannwald_usage']);
    $this->installSchema('migrate_tools', ['migrate_tools_sync_source_ids']);
    \Drupal::moduleHandler()->loadInclude('tannwald_ingest', 'install');
    tannwald_ingest_ensure_fulltext($this->container->get('database'));
    $this->installConfig(['system', 'filter', 'node', 'language', 'tannwald_access']);
    $this->importSyncConfig();
    // Uid 1 umgeht jede Zugriffsprüfung; ein Platzhalter belegt sie (admin).
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1])->save();
    // Wie node_install(): die Core-Zeile "all" (nid 0) — ohne Rebuild nach
    // dem Import sieht jedes Konto alles (C's Fund, Schritt 4 des Seeders).
    $this->container->get('database')->insert('node_access')->fields([
      'nid' => 0,
      'langcode' => '',
      'fallback' => 1,
      'gid' => 0,
      'realm' => 'all',
      'grant_view' => 1,
      'grant_update' => 0,
      'grant_delete' => 0,
    ])->execute();
    $this->mockProvider();
    $this->installer = $this->container->get('tannwald_demo.installer');
  }

  /**
   * Install, Inhalte, Zugriff, zweiter Lauf, Reparatur, Reset.
   */
  public function testInstallIsIdempotentAndResetRestores(): void {
    $report = $this->installer->install(['summaries' => FALSE]);
    foreach ($report['migrations'] as $id => $migration) {
      $this->assertSame(0, $migration['failed'], $id . ': ' . implode(' | ', $migration['messages']));
    }
    $this->assertSame(['created' => 10, 'existing' => 0], $report['terms']);
    $this->assertSame('set', $report['export_rules']);
    $this->assertSame('set', $report['admin']);
    $admin = $this->account('admin');
    $this->assertTrue($admin->hasRole('portal_admin'));
    $this->assertSame('admin@tannwald.example', $admin->getEmail());
    $this->assertSame('created', $report['confirmation']);
    $this->assertSame(2, $report['notify_cleared'], 'Two safety bulletins with confirmation duty queued mails, the seeder cleared them.');
    $this->assertSame(0, $this->container->get('queue')->get('tannwald_notify')->numberOfItems());
    $this->assertSame(0, $report['queues']['embed']['failed']);
    $first = $report['counts'];
    $this->assertSame(self::EXPECTED, array_intersect_key($first, self::EXPECTED));
    $this->assertGreaterThan(0, $first['chunks']);
    $this->assertSame(31 + 3, $first['chunk_documents'], 'One chunk set per published document revision and language, plus the three bulletins.');
    $this->assertSame(DemoInstaller::exportRules(), $this->config('tannwald_access.settings')->get('export_rules'));

    $this->assertDocuments();
    $this->assertBulletinsAndAccounts();
    $this->assertVisibility();

    // Zweiter Lauf: nichts ändert sich.
    $second = $this->installer->install(['summaries' => FALSE]);
    $this->assertSame(['created' => 0, 'existing' => 10], $second['terms']);
    $this->assertSame('unchanged', $second['export_rules']);
    $this->assertSame('unchanged', $second['admin']);
    $this->assertSame('unchanged', $second['confirmation']);
    foreach ($second['migrations'] as $id => $migration) {
      $changed = [$migration['created'], $migration['updated'], $migration['failed'], $migration['repaired']];
      $this->assertSame([0, 0, 0, 0], $changed, $id);
      $this->assertSame($migration['source'], $migration['unchanged'], $id);
    }
    $this->assertSame(0, $second['queues']['embed']['processed']);
    $this->assertSame($first, $second['counts']);

    // Ein von Hand gelöschtes Dokument repariert schon demo-install.
    $lookup = $this->container->get('tannwald_demo.lookup');
    $this->loadNode((int) $lookup->nidOf('wp-kt500'))->delete();
    $repaired = $this->installer->install(['summaries' => FALSE]);
    $this->assertSame(2, $repaired['migrations']['tannwald_dokumente']['repaired']);
    $this->assertSame($first, $repaired['counts']);

    // Reset nach Hand-Änderungen: Dokument weg, Lesebestätigung mehr, fremder
    // Inhalt dazu; admin und ein Nicht-Demo-Konto bleiben.
    $this->loadNode((int) $lookup->nidOf('ba-tb200'))->delete();
    $this->container->get('entity_type.manager')->getStorage('lesebestaetigung')->create([
      'uid' => $lookup->uidOf('kunde-a'),
      'bulletin_nid' => $lookup->nidOf('SB-2026-07'),
      'maschine_nid' => $lookup->nidOf('TB200-2021-0417'),
      'bestaetigt_am' => 1790000000,
      'ip_land' => 'CH',
    ])->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->create([
      'type' => 'maschine',
      'title' => 'HAND-0001',
      'uid' => $lookup->uidOf('admin'),
      'field_maschinentyp' => $lookup->termId('maschinentyp', 'TB200'),
      'field_firma' => $lookup->termId('firma', '100417'),
    ])->save();
    $userStorage = $this->container->get('entity_type.manager')->getStorage('user');
    $userStorage->create(['name' => 'gast', 'mail' => 'gast@example.com', 'status' => 1])->save();
    $this->assertNotSame($first, $this->installer->counts());

    $reset = $this->installer->reset(['summaries' => FALSE]);
    $this->assertSame(2, $reset['deleted']['lesebestaetigung']);
    $this->assertSame(1, $reset['deleted']['maschine'], 'The hand-made machine; the ten seeded ones go with the rollback.');
    $this->assertSame(10, $reset['deleted']['rolled_back']['tannwald_maschinen']);
    $this->assertSame(6, $reset['deleted']['rolled_back']['tannwald_users']);
    $this->assertSame($first, $reset['counts']);
    $this->assertSame('unchanged', $reset['admin'], 'demo-reset leaves admin alone.');
    $this->assertNotNull($lookup->uidOf('admin'));
    $this->assertNotNull($lookup->uidOf('gast'));
    $this->assertSame([], $storage->loadByProperties(['title' => 'HAND-0001']));
    $this->assertDocuments();
    $this->assertVisibility();
  }

  /**
   * Revisionen, Übersetzungen, Status, Dateien, Revisionsautoren.
   */
  protected function assertDocuments(): void {
    $lookup = $this->container->get('tannwald_demo.lookup');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    foreach ($storage->loadByProperties(['type' => 'dokument']) as $node) {
      foreach ($this->revisionIds((int) $node->id()) as $vid) {
        $revision = $this->loadRevision($vid);
        foreach (array_keys($revision->getTranslationLanguages()) as $langcode) {
          $translation = $revision->getTranslation($langcode);
          $label = $node->id() . '/' . $vid . '/' . $langcode;
          $this->assertSame('published', $translation->get('moderation_state')->value, $label);
          $this->assertTrue($translation->isPublished(), $label);
          $file = $translation->get('field_datei')->entity;
          $this->assertInstanceOf(FileInterface::class, $file, $label);
          $this->assertStringStartsWith('private://dokumente/', $file->getFileUri());
          $this->assertStringEndsWith('_' . $translation->get('field_version')->value . '_' . $langcode . '.html', $file->getFileUri());
          $this->assertTrue($file->isPermanent());
          $this->assertSame('dokument_html', $translation->get('body')->format);
        }
      }
    }
    $ba = $this->loadNode((int) $lookup->nidOf('ba-tb200'));
    $this->assertSame('3.2', $ba->get('field_version')->value);
    $this->assertEqualsCanonicalizing(['de', 'en', 'fr'], array_keys($ba->getTranslationLanguages()));
    $this->assertSame('de', $ba->getUntranslated()->language()->getId());
    $this->assertSame('techniker', $ba->getRevisionUser()->getAccountName());
    $this->assertSame('redaktion', $ba->getOwner()->getAccountName());
    $vids = $this->revisionIds((int) $ba->id());
    $this->assertCount(2, $vids);
    $old = $this->loadRevision(reset($vids));
    $this->assertSame('3.1', $old->get('field_version')->value);
    $this->assertSame(['de'], array_keys($old->getTranslationLanguages()));
    $this->assertSame('redaktion', $old->getRevisionUser()->getAccountName());
    $this->assertStringContainsString('Folienriss nach Rollenwechsel', $ba->get('field_aenderungsvermerk')->value);
    $this->assertStringContainsString('Chapter 7.3', $ba->getTranslation('en')->get('field_aenderungsvermerk')->value);

    $wp = $this->loadNode((int) $lookup->nidOf('wp-kt500'));
    $this->assertSame('en', $wp->language()->getId());
    $this->assertSame('2.0', $wp->get('field_version')->value);
    $ad = $this->loadNode((int) $lookup->nidOf('ad-tb200-2021-0417'));
    $this->assertSame((int) $lookup->nidOf('TB200-2021-0417'), (int) $ad->get('field_seriennummer')->target_id);
    $this->assertSame('klasse_2', $this->loadNode((int) $lookup->nidOf('sp-hf-siegelung'))->get('field_exportklasse')->value);
    $this->assertSame('2025-06-30', $this->loadNode((int) $lookup->nidOf('zert-kalibrierung-tb200'))->get('field_gueltig_bis')->value);
  }

  /**
   * Bulletins (Titel-Vertrag 2.5) und Demo-Konten.
   */
  protected function assertBulletinsAndAccounts(): void {
    $lookup = $this->container->get('tannwald_demo.lookup');
    $srv = $this->loadNode((int) $lookup->nidOf('SRV-2026-19'));
    $this->assertSame('SRV-2026-19 Folienspannung nach Rollenwechsel', $srv->label());
    $this->assertSame('published', $srv->get('moderation_state')->value);
    $this->assertSame('redaktion', $srv->getOwner()->getAccountName());
    $this->assertSame((new \DateTimeImmutable('2026-08-22 08:00', new \DateTimeZone('Europe/Zurich')))->getTimestamp(), (int) $srv->getCreatedTime());
    $this->assertSame('sicherheit', $this->loadNode((int) $lookup->nidOf('SB-2026-07'))->get('field_bulletin_art')->value);

    $fixture = json_decode((string) file_get_contents(dirname(DRUPAL_ROOT) . '/fixtures/sap/nutzer.json'), TRUE);
    $this->assertSame(array_column($fixture, 'anzeigename', 'name'), DisplayNames::ACCOUNTS);
    $userStorage = $this->container->get('entity_type.manager')->getStorage('user');
    $kundeA = $this->account('kunde-a');
    $this->assertSame('Anna Keller', $kundeA->getDisplayName());
    $this->assertSame('kunde-a@tannwald.example', $kundeA->getEmail());
    $this->assertSame(['authenticated', 'kunde'], $kundeA->getRoles());
    $this->assertSame((int) $lookup->termId('firma', '100417'), (int) $kundeA->get('field_firma')->target_id);
    $this->assertSame('en', $this->account('kunde-b')->getPreferredLangcode());
    $this->assertTrue($this->account('techniker')->get('field_firma')->isEmpty());
    $this->assertSame((int) $kundeA->id(), (int) $this->container->get('user.auth')->authenticateAccount($kundeA, 'demo') ? (int) $kundeA->id() : 0);
    $this->assertCount(7, $userStorage->loadMultiple(), 'Six demo accounts and admin.');
    $territory = array_column($this->container->get('entity_type.manager')->getStorage('taxonomy_term')
      ->load($lookup->termId('firma', '200031'))->get('field_haendler_gebiet')->getValue(), 'value');
    $this->assertSame(['BR', 'MX', 'AR', 'CL'], $territory);
  }

  /**
   * Sichtbarkeit am echten Seed (Grants von 2.4, Erwartung B-Bericht 3 g).
   */
  protected function assertVisibility(): void {
    $lookup = $this->container->get('tannwald_demo.lookup');
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $tb200 = [
      'ba-tb200', 'ek-tb200', 'wp-tb200', 'sp-hf-siegelung', 'ad-tb200-2021-0417',
      'zert-konformitaet-tb200', 'zert-kalibrierung-tb200', 'su-tb200-formatwechsel',
    ];
    $kundeA = $this->account('kunde-a');
    $visibleForA = [
      'TB200-2021-0417', 'ba-tb200', 'ek-tb200', 'sp-hf-siegelung', 'wp-tb200', 'ad-tb200-2021-0417', 'SRV-2026-19',
    ];
    foreach ($visibleForA as $id) {
      $this->assertTrue($this->loadNode((int) $lookup->nidOf($id))->access('view', $kundeA), 'kunde-a sees ' . $id);
    }
    $kundeB = $this->account('kunde-b');
    foreach ([...$tb200, 'sp-tb400', 'sp-kt500', 'TB200-2021-0417', 'SB-2026-07'] as $id) {
      $this->assertFalse($this->loadNode((int) $lookup->nidOf($id))->access('view', $kundeB), 'kunde-b must not see ' . $id);
    }
    $this->assertTrue($this->loadNode((int) $lookup->nidOf('ba-tb400'))->access('view', $kundeB));
    $haendler = $this->account('haendler');
    $this->assertFalse($this->loadNode((int) $lookup->nidOf('sp-hf-siegelung'))->access('view', $haendler));
    $this->assertFalse($this->loadNode((int) $lookup->nidOf('ad-tb200-2021-0417'))->access('view', $haendler));
    $this->assertTrue($this->loadNode((int) $lookup->nidOf('sp-tb400'))->access('view', $haendler));
    $this->assertTrue($this->loadNode((int) $lookup->nidOf('ba-tb200'))->access('view', $haendler));

    // Listen mit Node-Access wie /meine-maschinen.
    $machines = function (UserInterface $account): array {
      $this->container->get('current_user')->setAccount($account);
      $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(TRUE)->condition('type', 'maschine')->condition('status', 1)->sort('title');
      $titles = array_map(fn(NodeInterface $node) => $node->label(), $this->container->get('entity_type.manager')->getStorage('node')->loadMultiple($query->execute()));
      return array_values($titles);
    };
    $this->assertSame(['KT500-2022-0152', 'TB200-2021-0417', 'TB400-2023-0088'], $machines($kundeA));
    $this->assertSame(['KT500-2021-0097', 'TB400-2020-0051'], $machines($kundeB));
  }

  /**
   * Lädt einen Node frisch.
   */
  protected function loadNode(int $nid): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    $this->assertInstanceOf(NodeInterface::class, $node, 'Node ' . $nid);
    return $node;
  }

  /**
   * Revisions-IDs eines Nodes, aufsteigend.
   *
   * @return int[]
   *   Revisions-IDs.
   */
  protected function revisionIds(int $nid): array {
    $vids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->allRevisions()->condition('nid', $nid)->sort('vid')->execute();
    return array_map('intval', array_keys($vids));
  }

  /**
   * Lädt eine Revision.
   */
  protected function loadRevision(int $vid): NodeInterface {
    $revision = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($vid);
    $this->assertInstanceOf(NodeInterface::class, $revision, 'Revision ' . $vid);
    return $revision;
  }

  /**
   * Lädt ein Demo-Konto.
   */
  protected function account(string $name): UserInterface {
    $uid = $this->container->get('tannwald_demo.lookup')->uidOf($name);
    $this->assertNotNull($uid, $name);
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$uid]);
    return $storage->load($uid);
  }

  /**
   * Legt die nötige Konfiguration aus config/sync an.
   *
   * Drittanbieter-Einstellungen nicht installierter Module und unbekannte
   * Permissions werden weggelassen (Kernel-Test ohne volle Site).
   */
  protected function importSyncConfig(): void {
    $directory = dirname(DRUPAL_ROOT) . '/config/sync';
    $configManager = $this->container->get('config.manager');
    $entityTypeManager = $this->container->get('entity_type.manager');
    $moduleHandler = $this->container->get('module_handler');
    foreach (self::CONFIG_PATTERNS as $pattern) {
      $files = glob($directory . '/' . $pattern . '.yml') ?: [];
      $this->assertNotEmpty($files, $pattern);
      foreach ($files as $file) {
        $name = basename($file, '.yml');
        if (str_starts_with($name, 'language.entity.')) {
          ConfigurableLanguage::createFromLangcode(substr($name, 16))->save();
          continue;
        }
        $data = Yaml::decode((string) file_get_contents($file));
        unset($data['_core']);
        foreach (array_keys($data['third_party_settings'] ?? []) as $module) {
          if (!$moduleHandler->moduleExists($module)) {
            unset($data['third_party_settings'][$module]);
          }
        }
        if (isset($data['permissions'])) {
          $known = array_keys($this->container->get('user.permissions')->getPermissions());
          $data['permissions'] = array_values(array_intersect($data['permissions'], $known));
        }
        $entityTypeId = $configManager->getEntityTypeIdByName($name);
        $this->assertNotNull($entityTypeId, $name);
        $storage = $entityTypeManager->getStorage($entityTypeId);
        $this->assertInstanceOf(ConfigEntityStorageInterface::class, $storage);
        $storage->createFromStorageRecord($data)->save();
      }
    }
  }

  /**
   * Ersetzt ai.provider durch ein Double mit deterministischen Vektoren.
   */
  protected function mockProvider(): void {
    $proxy = $this->getMockBuilder(ProviderProxy::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['__call'])
      ->getMock();
    $proxy->method('__call')->willReturnCallback(function (string $name, array $arguments) {
      if ($name === 'getConfiguredModels') {
        return ['bge_m3_latest' => 'bge-m3:latest'];
      }
      $this->assertSame('embeddingsCollection', $name, 'The seeder test only embeds.');
      /** @var \Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput $input */
      $input = $arguments[0];
      $vectors = array_map([self::class, 'hashVector'], $input->getPrompts());
      $raw = ['model' => 'bge-m3:latest', 'usage' => ['prompt_tokens' => 10 * $input->getPromptCount()]];
      return new EmbeddingsCollectionOutput($vectors, $raw, []);
    });
    $this->config('ai.settings')
      ->set('default_providers.embeddings', ['provider_id' => 'ollama', 'model_id' => 'bge_m3_latest'])
      ->save();
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('createInstance')->willReturn($proxy);
    $this->container->set('ai.provider', $manager);
  }

  /**
   * Deterministischer 1024er-Vektor aus dem Text-Hash.
   *
   * @return float[]
   *   Vektor.
   */
  public static function hashVector(string $text): array {
    $seed = crc32($text);
    $vector = [];
    for ($i = 0; $i < 1024; $i++) {
      $vector[] = sin($seed + $i * 0.37);
    }
    return $vector;
  }

}
