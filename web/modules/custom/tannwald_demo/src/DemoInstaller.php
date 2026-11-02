<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_tools\MigrateExecutable;
use Drupal\node\NodeAccessRebuild;
use Psr\Log\LoggerInterface;

/**
 * Der Seeder: tannwald:demo-install und tannwald:demo-reset.
 *
 * Alle Schritte sind idempotent; ein zweiter Lauf ändert nichts und meldet
 * "unverändert". Die Drush-Befehle sind nur die Hülle (Ausgabe, Schutz vor
 * Produktion); DemoInstallTest ruft diesen Dienst direkt.
 */
class DemoInstaller {

  /**
   * Migrationen in Import-Reihenfolge (Rollback rückwärts).
   */
  public const MIGRATIONS = [
    'tannwald_firmen',
    'tannwald_maschinen',
    'tannwald_users',
    'tannwald_dokumente',
    'tannwald_bulletins',
    'tannwald_bilder',
  ];

  /**
   * Ziel-Entity-Typ je Migration (für die Reparatur gelöschter Ziele).
   */
  public const TARGET_TYPES = [
    'tannwald_firmen' => 'taxonomy_term',
    'tannwald_maschinen' => 'node',
    'tannwald_users' => 'user',
    'tannwald_dokumente' => 'node',
    'tannwald_bulletins' => 'node',
    // Gemischte Ziele (Term oder Node): der Typ steht in der Map-Zeile.
    'tannwald_bilder' => NULL,
  ];

  /**
   * Terme maschinentyp: field_typ_code => Name.
   */
  public const MACHINE_TYPES = ['TB200' => 'TB-200', 'TB400' => 'TB-400', 'KT500' => 'KT-500'];

  /**
   * Terme dokumenttyp: field_slug => Name (Wortliste CLAUDE.md).
   */
  public const DOCUMENT_TYPES = [
    'betriebsanleitung' => 'Betriebsanleitung',
    'ersatzteilkatalog' => 'Ersatzteilkatalog',
    'schaltplan' => 'Schaltplan',
    'wartungsplan' => 'Wartungsplan',
    'zertifikat' => 'Zertifikat',
    'schulungsunterlage' => 'Schulungsunterlage',
    'anlagenspezifisch' => 'Anlagenspezifische Dokumentation',
  ];

  /**
   * Exportregeln-Seed: Länder für klasse_2; klasse_1 zusätzlich DEALER_COUNTRY.
   */
  public const EXPORT_COUNTRIES = ['CH', 'DE', 'AT', 'FR', 'IT', 'NL', 'US', 'JP'];

  /**
   * Händlerland (Embalatec Serviços Ltda., B-Bericht 2.2).
   */
  public const DEALER_COUNTRY = 'BR';

  /**
   * Bundles, deren Nodes demo-reset vollständig löscht.
   */
  public const RESET_BUNDLES = ['dokument', 'bulletin', 'zusammenfassung', 'maschine'];

  /**
   * Queues von tannwald_ingest in Verarbeitungsreihenfolge.
   */
  public const INGEST_QUEUES = ['embed' => 'tannwald_embed', 'summarize' => 'tannwald_summarize'];

  /**
   * Mail-Queue von tannwald_bulletin (Entscheid 18: beim Seeden leeren).
   */
  public const NOTIFY_QUEUE = 'tannwald_notify';

  /**
   * Konto admin: Rolle portal_admin und Mail (Nachtrag 4: Warnmail aus 4.2).
   */
  public const ADMIN = [
    'uid' => 1,
    'mail' => 'admin@tannwald.example',
    'role' => 'portal_admin',
  ];

  /**
   * Seed-Zeile Lesebestätigung (Wireframe 01: 03.10.2026 durch Anna Keller).
   */
  public const CONFIRMATION = [
    'account' => 'kunde-a',
    'bulletin' => 'SRV-2026-19',
    'machine' => 'TB200-2021-0417',
    'time' => '2026-10-03 10:00:00',
    'country' => 'CH',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MigrationPluginManagerInterface $migrationManager,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager,
    protected Connection $database,
    protected AccountSwitcherInterface $accountSwitcher,
    protected KeyValueFactoryInterface $keyValue,
    protected TimeInterface $time,
    protected TranslationInterface $translation,
    protected NodeAccessRebuild $nodeAccessRebuild,
    protected DemoLookup $lookup,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Spielt die Demo-Daten ein (idempotent).
   *
   * @param array{queues?: bool, summaries?: bool} $options
   *   queues: Ingest-Queues laufen lassen (Standard TRUE); summaries: auch
   *   tannwald_summarize (Standard TRUE, --skip-summaries setzt FALSE).
   *
   * @return array<string, mixed>
   *   Bericht: terms, export_rules, migrations, confirmation, queues,
   *   notify_cleared, counts, seconds.
   */
  public function install(array $options = []): array {
    $start = microtime(TRUE);
    $report = [];
    $this->asAdmin(function () use ($options, &$report): void {
      $report['terms'] = $this->ensureTerms();
      $report['admin'] = $this->ensureAdmin() ? 'set' : 'unchanged';
      $report['export_rules'] = $this->applyExportRules() ? 'set' : 'unchanged';
      $report['migrations'] = $this->runMigrations();
      $report['images'] = $this->imageSummary();
      $rebuildStart = microtime(TRUE);
      $this->nodeAccessRebuild->rebuild();
      $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
      $report['rebuild_seconds'] = round(microtime(TRUE) - $rebuildStart, 1);
      $report['confirmation'] = $this->seedConfirmation();
      $report['queues'] = ($options['queues'] ?? TRUE)
        ? $this->runIngestQueues($options['summaries'] ?? TRUE)
        : [];
      $report['notify_cleared'] = $this->clearNotifyQueue();
    });
    $report['counts'] = $this->counts();
    $report['seconds'] = round(microtime(TRUE) - $start, 1);
    return $report;
  }

  /**
   * Setzt die Demo-Daten zurück und spielt sie neu ein.
   *
   * Löscht: alle lesebestaetigung und support_anfrage, alle Nodes der
   * Bundles RESET_BUNDLES (auch von Hand angelegte), die sechs Demo-Konten
   * und die Firmen-Terme der Migration (Rollback, Map-Tabellen leer). Bleibt:
   * Terme maschinentyp/dokumenttyp, tannwald_audit, tannwald_assistant_log,
   * tannwald_usage, alle anderen Nutzer (admin).
   *
   * @return array<string, mixed>
   *   Bericht: deleted (je Art), dann wie install().
   */
  public function reset(array $options = []): array {
    $start = microtime(TRUE);
    $deleted = [];
    $this->asAdmin(function () use (&$deleted): void {
      // Offene Ingest-Jobs gelten den Nodes, die gleich verschwinden; leeren
      // verkleinert das Fenster, in dem Cron parallel noch etwas speichert.
      foreach (self::INGEST_QUEUES as $key => $name) {
        $deleted['queue_' . $key] = (int) $this->queueFactory->get($name)->numberOfItems();
        $this->queueFactory->get($name)->deleteQueue();
      }
      $deleted['lesebestaetigung'] = $this->deleteAllEntities('lesebestaetigung');
      $deleted['support_anfrage'] = $this->deleteAllEntities('support_anfrage');
      $this->guardUserMap();
      $rolledBack = [];
      // Bilder zuerst: Rollback leert nur field_bild, Dateien bleiben.
      foreach (['tannwald_bilder', 'tannwald_bulletins', 'tannwald_dokumente'] as $id) {
        $rolledBack[$id] = $this->rollback($id);
      }
      foreach (['dokument', 'bulletin', 'zusammenfassung'] as $bundle) {
        $deleted[$bundle] = $this->deleteNodes($bundle);
      }
      foreach (['tannwald_users', 'tannwald_maschinen', 'tannwald_firmen'] as $id) {
        $rolledBack[$id] = $this->rollback($id);
      }
      $deleted['maschine'] = $this->deleteNodes('maschine');
      $deleted['rolled_back'] = $rolledBack;
    });
    $report = $this->install($options);
    $report['deleted'] = $deleted;
    $report['seconds'] = round(microtime(TRUE) - $start, 1);
    return $report;
  }

  /**
   * Legt die Terme maschinentyp und dokumenttyp an, falls sie fehlen.
   *
   * @return array{created: int, existing: int}
   *   Zählung.
   */
  public function ensureTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $result = ['created' => 0, 'existing' => 0];
    $sets = [
      'maschinentyp' => ['field_typ_code', self::MACHINE_TYPES],
      'dokumenttyp' => ['field_slug', self::DOCUMENT_TYPES],
    ];
    foreach ($sets as $vocab => [$field, $terms]) {
      $weight = 0;
      foreach ($terms as $code => $name) {
        if ($this->lookup->termId($vocab, (string) $code) !== NULL) {
          $result['existing']++;
        }
        else {
          $storage->create(['vid' => $vocab, 'name' => $name, $field => $code, 'weight' => $weight, 'langcode' => 'de'])->save();
          $result['created']++;
        }
        $weight++;
      }
    }
    return $result;
  }

  /**
   * Gibt admin (uid 1) die Rolle portal_admin und die Demo-Mail.
   *
   * @return bool
   *   TRUE, wenn gespeichert wurde.
   */
  public function ensureAdmin(): bool {
    /** @var \Drupal\user\UserInterface|null $admin */
    $admin = $this->entityTypeManager->getStorage('user')->load(self::ADMIN['uid']);
    if ($admin === NULL) {
      return FALSE;
    }
    $changed = FALSE;
    if ($this->entityTypeManager->getStorage('user_role')->load(self::ADMIN['role']) !== NULL && !$admin->hasRole(self::ADMIN['role'])) {
      $admin->addRole(self::ADMIN['role']);
      $changed = TRUE;
    }
    if ($admin->getEmail() !== self::ADMIN['mail']) {
      $admin->setEmail(self::ADMIN['mail']);
      $changed = TRUE;
    }
    if ($changed) {
      $admin->save();
    }
    return $changed;
  }

  /**
   * Soll-Werte der Exportregeln (gleich wie config/sync).
   *
   * @return array<string, array{laender: string[], gesperrte_firmen: string[]}>
   *   export_rules.
   */
  public static function exportRules(): array {
    return [
      'klasse_1' => ['laender' => [...self::EXPORT_COUNTRIES, self::DEALER_COUNTRY], 'gesperrte_firmen' => []],
      'klasse_2' => ['laender' => self::EXPORT_COUNTRIES, 'gesperrte_firmen' => []],
    ];
  }

  /**
   * Setzt die Exportregeln, wenn sie abweichen (ohne Formular, ohne Audit).
   *
   * @return bool
   *   TRUE, wenn geschrieben wurde.
   */
  public function applyExportRules(): bool {
    $config = $this->configFactory->getEditable('tannwald_access.settings');
    if ($config->get('export_rules') === self::exportRules()) {
      return FALSE;
    }
    $config->set('export_rules', self::exportRules())->save();
    return TRUE;
  }

  /**
   * Zuordnung der Bilder laut Manifest, gemessen am Ziel.
   *
   * @return array{files_assigned: int, targets: int, theme_assets: int, missing: string[]}
   *   files_assigned: Dateien, deren Ziele alle das Bild tragen; targets:
   *   Ziele mit dem Bild; theme_assets: Dateien mit zweck "hero"; missing:
   *   "datei → ziel" ohne Bild.
   */
  public function imageSummary(): array {
    $files = [];
    $summary = ['files_assigned' => 0, 'targets' => 0, 'theme_assets' => 0, 'missing' => []];
    $fileStorage = $this->entityTypeManager->getStorage('file');
    foreach ($this->lookup->imageManifest() as $entry) {
      $name = (string) ($entry['datei'] ?? '');
      $purpose = (string) ($entry['zweck'] ?? '');
      if ($purpose === 'hero') {
        $summary['theme_assets']++;
        continue;
      }
      $fid = $this->lookup->imageFor($purpose, (string) ($entry['ziel'] ?? ''));
      $file = $fid === NULL ? NULL : $fileStorage->load($fid);
      $ok = $file !== NULL && basename((string) $file->getFileUri()) === $name;
      $files[$name] = ($files[$name] ?? TRUE) && $ok;
      if ($ok) {
        $summary['targets']++;
      }
      else {
        $summary['missing'][] = $name . ' → ' . ($entry['ziel'] ?? '');
      }
    }
    $summary['files_assigned'] = count(array_filter($files));
    return $summary;
  }

  /**
   * Führt die Migrationen aus.
   *
   * @return array<string, array<string, int|string[]>>
   *   Je Migration: source, created, updated, unchanged, failed, repaired,
   *   messages.
   */
  public function runMigrations(): array {
    $result = [];
    foreach (self::MIGRATIONS as $id) {
      $migration = $this->migration($id);
      $repaired = $this->markMissingForUpdate($migration);
      $messages = $this->messageCollector();
      $executable = new MigrateExecutable($migration, $messages, $this->keyValue, $this->time, $this->translation, []);
      $executable->import();
      $source = count($migration->getSourcePlugin());
      $processed = (int) $executable->getProcessedCount();
      $result[$id] = [
        'source' => $source,
        'created' => (int) $executable->getCreatedCount(),
        'updated' => (int) $executable->getUpdatedCount(),
        'unchanged' => max(0, $source - $processed),
        'failed' => (int) $executable->getFailedCount(),
        'repaired' => $repaired,
        'messages' => $messages->messages,
      ];
      foreach ($messages->messages as $message) {
        $this->logger->warning('@id: @message', ['@id' => $id, '@message' => $message]);
      }
    }
    return $result;
  }

  /**
   * Legt die Seed-Lesebestätigung an (direkt, ohne ConfirmationService).
   *
   * @return string
   *   created, unchanged, skipped_module, skipped_data.
   */
  public function seedConfirmation(): string {
    if (!$this->moduleHandler->moduleExists('tannwald_bulletin') || !$this->entityTypeManager->hasDefinition('lesebestaetigung')) {
      return 'skipped_module';
    }
    $bulletinNid = $this->lookup->nidOf(self::CONFIRMATION['bulletin']);
    $machineNid = $this->lookup->nidOf(self::CONFIRMATION['machine']);
    $uid = $this->lookup->uidOf(self::CONFIRMATION['account']);
    if ($bulletinNid === NULL || $machineNid === NULL || $uid === NULL) {
      return 'skipped_data';
    }
    $storage = $this->entityTypeManager->getStorage('lesebestaetigung');
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bulletin_nid', $bulletinNid)
      ->condition('maschine_nid', $machineNid)
      ->condition('uid', $uid)
      ->count()
      ->execute();
    if ((int) $existing > 0) {
      return 'unchanged';
    }
    $confirmedAt = (new \DateTimeImmutable(self::CONFIRMATION['time'], new \DateTimeZone('Europe/Zurich')))->getTimestamp();
    $storage->create([
      'uid' => $uid,
      'bulletin_nid' => $bulletinNid,
      'maschine_nid' => $machineNid,
      'bestaetigt_am' => $confirmedAt,
      'ip_land' => self::CONFIRMATION['country'],
    ])->save();
    return 'created';
  }

  /**
   * Verarbeitet tannwald_embed und (optional) tannwald_summarize bis leer.
   *
   * Fehler (Ollama nicht erreichbar) brechen den Seed nicht ab: der Worker
   * versucht dreimal, dann fehlen die Chunks (tannwald:reindex holt nach).
   *
   * @return array<string, array<string, int|float|string>>
   *   Je Queue: processed, ok, skipped, retry, failed, left, seconds, status.
   */
  public function runIngestQueues(bool $summaries = TRUE): array {
    $result = [];
    if (!$this->moduleHandler->moduleExists('tannwald_ingest')) {
      return ['embed' => ['status' => 'skipped_module']];
    }
    foreach (self::INGEST_QUEUES as $key => $name) {
      if ($key === 'summarize' && !$summaries) {
        $result[$key] = ['status' => 'skipped_option', 'left' => $this->queueFactory->get($name)->numberOfItems()];
        continue;
      }
      if (!$this->queueWorkerManager->hasDefinition($name)) {
        $result[$key] = ['status' => 'skipped_worker'];
        continue;
      }
      $result[$key] = $this->runQueue($name);
    }
    return $result;
  }

  /**
   * Leert tannwald_notify (die Demo-Mails entstehen bei der Live-Freigabe).
   *
   * @return int
   *   Gelöschte Jobs.
   */
  public function clearNotifyQueue(): int {
    $queue = $this->queueFactory->get(self::NOTIFY_QUEUE);
    $count = (int) $queue->numberOfItems();
    if ($count > 0) {
      $queue->deleteQueue();
    }
    return $count;
  }

  /**
   * Zählungen der Demo-Daten.
   *
   * @return array<string, int|string>
   *   Schlüssel firmen, maschinen, users, dokumente, revisionen,
   *   revision_translations, uebersetzungen, bulletins, chunks,
   *   chunk_documents, zusammenfassungen, zusammenfassungen_zur_pruefung,
   *   lesebestaetigungen.
   */
  public function counts(): array {
    $nodes = fn(string $bundle): int => (int) $this->database->query('SELECT COUNT(*) FROM {node} WHERE type = :t', [':t' => $bundle])->fetchField();
    $counts = [
      'firmen' => (int) $this->database->query("SELECT COUNT(*) FROM {taxonomy_term_data} WHERE vid = 'firma'")->fetchField(),
      'maschinen' => $nodes('maschine'),
      'users' => (int) $this->database->query('SELECT COUNT(*) FROM {users_field_data} WHERE name IN (:names[])', [':names[]' => array_keys(DisplayNames::ACCOUNTS)])->fetchField(),
      'dokumente' => $nodes('dokument'),
      'revisionen' => (int) $this->database->query("SELECT COUNT(*) FROM {node_revision} r INNER JOIN {node} n ON n.nid = r.nid WHERE n.type = 'dokument'")->fetchField(),
      'revision_translations' => (int) $this->database->query("SELECT COUNT(*) FROM {node_field_revision} r INNER JOIN {node} n ON n.nid = r.nid WHERE n.type = 'dokument'")->fetchField(),
      'uebersetzungen' => (int) $this->database->query("SELECT COUNT(*) FROM {node_field_data} WHERE type = 'dokument' AND default_langcode = 0")->fetchField(),
      'bulletins' => $nodes('bulletin'),
      'chunks' => 'n/a',
      'chunk_documents' => 'n/a',
      'zusammenfassungen' => $nodes('zusammenfassung'),
      'zusammenfassungen_zur_pruefung' => $this->countSummariesInReview(),
      'lesebestaetigungen' => 'n/a',
      'images' => 'n/a',
    ];
    if ($this->database->schema()->tableExists('tannwald_chunk')) {
      $counts['chunks'] = (int) $this->database->query('SELECT COUNT(*) FROM {tannwald_chunk}')->fetchField();
      $counts['chunk_documents'] = (int) $this->database->query('SELECT COUNT(DISTINCT nid, vid, langcode) FROM {tannwald_chunk}')->fetchField();
    }
    $imageTables = ['node__' . DemoLookup::IMAGE_FIELD, 'taxonomy_term__' . DemoLookup::IMAGE_FIELD];
    if (array_filter($imageTables, [$this->database->schema(), 'tableExists']) === $imageTables) {
      $counts['images'] = 0;
      foreach ($imageTables as $table) {
        $counts['images'] += (int) $this->database->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField();
      }
    }
    if ($this->entityTypeManager->hasDefinition('lesebestaetigung')) {
      $counts['lesebestaetigungen'] = (int) $this->entityTypeManager->getStorage('lesebestaetigung')->getQuery()->accessCheck(FALSE)->count()->execute();
    }
    return $counts;
  }

  /**
   * Zusammenfassungen, deren neueste Revision "zur_pruefung" ist.
   */
  protected function countSummariesInReview(): int {
    if (!$this->database->schema()->tableExists('content_moderation_state_field_revision')) {
      return 0;
    }
    return (int) $this->database->query("SELECT COUNT(DISTINCT n.nid) FROM {node} n
      INNER JOIN {content_moderation_state_field_revision} m
        ON m.content_entity_type_id = 'node' AND m.content_entity_id = n.nid
      WHERE n.type = 'zusammenfassung' AND m.moderation_state = 'zur_pruefung'
        AND m.content_entity_revision_id = (SELECT MAX(r.vid) FROM {node_revision} r WHERE r.nid = n.nid)")->fetchField();
  }

  /**
   * Verarbeitet eine Queue bis leer.
   *
   * @return array<string, int|float|string>
   *   Zählung und Dauer.
   */
  protected function runQueue(string $name): array {
    $start = microtime(TRUE);
    $queue = $this->queueFactory->get($name);
    $worker = $this->queueWorkerManager->createInstance($name);
    $counts = ['processed' => 0, 'ok' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
    $status = 'done';
    // Schutz gegen eine Queue, die sich selbst füllt.
    $guard = 50 * max(100, (int) $queue->numberOfItems());
    while ($guard-- > 0 && is_object($item = $queue->claimItem(300))) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $counts['processed']++;
        $outcome = method_exists($worker, 'lastOutcome') ? (string) $worker->lastOutcome() : 'ok';
        $counts[isset($counts[$outcome]) ? $outcome : 'ok']++;
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $counts['retry']++;
      }
      catch (SuspendQueueException $exception) {
        $queue->releaseItem($item);
        $status = 'suspended: ' . $exception->getMessage();
        break;
      }
      catch (\Throwable $exception) {
        $queue->deleteItem($item);
        $counts['processed']++;
        $counts['failed']++;
        $this->logger->error('@queue: @message', ['@queue' => $name, '@message' => $exception->getMessage()]);
      }
    }
    return $counts + [
      'left' => (int) $queue->numberOfItems(),
      'seconds' => round(microtime(TRUE) - $start, 1),
      'status' => $status,
    ];
  }

  /**
   * Markiert Map-Zeilen ohne vorhandenes Ziel zur erneuten Übernahme.
   *
   * So repariert ein zweiter demo-install von Hand gelöschte Demo-Inhalte
   * und zuvor gescheiterte Zeilen (track_changes allein tut das nicht).
   *
   * @return int
   *   Markierte Zeilen.
   */
  protected function markMissingForUpdate(MigrationInterface $migration): int {
    $idMap = $migration->getIdMap();
    $entityTypeId = self::TARGET_TYPES[$migration->id()];
    $missing = [];
    $idMap->rewind();
    for (; $idMap->valid(); $idMap->next()) {
      $source = $idMap->currentSource();
      $destination = $idMap->currentDestination();
      if ($entityTypeId === NULL) {
        // Bilder: übersprungene Zeilen (hero) bleiben übersprungen; fehlt das
        // Ziel oder ist field_bild leer, wird die Zeile neu übernommen.
        if ((int) ($idMap->getRowBySource($source)['source_row_status'] ?? 0) === MigrateIdMapInterface::STATUS_IGNORED) {
          continue;
        }
        if ($this->imageTargetMissing(array_values((array) $destination))) {
          $missing[] = $source;
        }
        continue;
      }
      $target = empty($destination) ? NULL : reset($destination);
      if (empty($target) || $this->entityTypeManager->getStorage($entityTypeId)->load($target) === NULL) {
        $missing[] = $source;
      }
    }
    foreach ($missing as $source) {
      $idMap->setUpdate($source);
    }
    return count($missing);
  }

  /**
   * TRUE, wenn ein Bild-Ziel [entity_type, entity_id] fehlt oder kein Bild hat.
   *
   * @param array<int, mixed> $destination
   *   Ziel-IDs aus der Map.
   */
  protected function imageTargetMissing(array $destination): bool {
    [$type, $id] = $destination + [NULL, NULL];
    if (empty($type) || empty($id) || !$this->entityTypeManager->hasDefinition((string) $type)) {
      return TRUE;
    }
    $entity = $this->entityTypeManager->getStorage((string) $type)->load($id);
    return !$entity instanceof FieldableEntityInterface
      || !$entity->hasField(DemoLookup::IMAGE_FIELD)
      || $entity->get(DemoLookup::IMAGE_FIELD)->isEmpty();
  }

  /**
   * Rollt eine Migration zurück.
   *
   * @return int
   *   Zurückgerollte Zeilen.
   */
  protected function rollback(string $id): int {
    $migration = $this->migration($id);
    $executable = new MigrateExecutable($migration, $this->messageCollector(), $this->keyValue, $this->time, $this->translation, []);
    $executable->rollback();
    return (int) $executable->getRollbackCount();
  }

  /**
   * Bricht ab, wenn die Nutzer-Map auf ein Nicht-Demo-Konto zeigt.
   */
  protected function guardUserMap(): void {
    $idMap = $this->migration('tannwald_users')->getIdMap();
    $storage = $this->entityTypeManager->getStorage('user');
    $idMap->rewind();
    for (; $idMap->valid(); $idMap->next()) {
      $destination = $idMap->currentDestination();
      $uid = empty($destination) ? 0 : (int) reset($destination);
      if ($uid === 0) {
        continue;
      }
      $account = $storage->load($uid);
      if ($uid === 1 || ($account !== NULL && DisplayNames::of((string) $account->getAccountName()) === NULL)) {
        throw new \RuntimeException(sprintf('tannwald_users maps to uid %d, which is not a demo account; reset aborted.', $uid));
      }
    }
  }

  /**
   * Löscht alle Nodes eines Bundles in Paketen.
   */
  protected function deleteNodes(string $bundle): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->execute();
    foreach (array_chunk($nids, 50) as $chunk) {
      $storage->delete($storage->loadMultiple($chunk));
    }
    return count($nids);
  }

  /**
   * Löscht alle Entities eines Typs, falls der Typ existiert.
   */
  protected function deleteAllEntities(string $entityTypeId): int|string {
    if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return 'n/a';
    }
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach (array_chunk($ids, 50) as $chunk) {
      $storage->delete($storage->loadMultiple($chunk));
    }
    return count($ids);
  }

  /**
   * Migration im Leerlauf (ein abgebrochener Lauf hinterlässt "importing").
   */
  protected function migration(string $id): MigrationInterface {
    /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
    $migration = $this->migrationManager->createInstance($id);
    if ($migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
      $migration->setStatus(MigrationInterface::STATUS_IDLE);
    }
    return $migration;
  }

  /**
   * Sammelt Migrate-Meldungen für die Ausgabe.
   */
  protected function messageCollector(): MessageCollector {
    return new MessageCollector();
  }

  /**
   * Führt den Seed als uid 1 aus (Audit "freigabe" nennt dann admin).
   */
  protected function asAdmin(callable $callback): void {
    $admin = $this->entityTypeManager->getStorage('user')->load(1);
    $this->accountSwitcher->switchTo($admin ?? new AnonymousUserSession());
    try {
      $callback();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
