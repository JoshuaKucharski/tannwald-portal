<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Plugin\migrate\destination;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\FileInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\node\NodeInterface;
use Drupal\tannwald_demo\DemoLookup;
use Drupal\tannwald_demo\FixturePath;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Eine Dokument-Version als Revision eines Nodes "dokument".
 *
 * Je Quellzeile (id, version) entsteht genau eine Revision: die erste Zeile
 * einer Manifest-ID legt den Node an, jede weitere eine neue
 * Standard-Revision. Alle Sprachen derselben Version sind Übersetzungen
 * dieser einen Revision (ein Save, also ein Embed-Job je Sprache und keine
 * doppelten Chunks). Jede Übersetzung erhält moderation_state "published".
 * Eine geänderte Quellzeile (track_changes) aktualisiert ihre Revision an Ort
 * und Stelle, ohne neue Revision.
 *
 * Konfiguration:
 * - default_author: Konto für uid und Revisionsautor (redaktion).
 * - revision_authors: Liste {id, version, user} mit abweichendem
 *   Revisionsautor (Wireframe 03: BA TB-200 3.2 durch Marco Steiner).
 * - fixture_directory: Unterordner von fixtures/ mit den HTML-Dateien.
 * - file_directory: Ziel der Dateien (private://dokumente).
 * - text_format: Format für body (dokument_html).
 */
#[MigrateDestination('tannwald_document_revision')]
final class DocumentRevision extends DestinationBase implements ContainerFactoryPluginInterface {

  /**
   * Zeitzone für Datumswerte aus dem Manifest.
   */
  public const TIMEZONE = 'Europe/Zurich';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected DemoLookup $lookup,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->supportsRollback = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get(DemoLookup::class),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'nid' => ['type' => 'integer', 'unsigned' => TRUE],
      'vid' => ['type' => 'integer', 'unsigned' => TRUE],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(?MigrationInterface $migration = NULL) {
    return [
      'id' => 'Manifest ID',
      'version' => 'Version',
      'titel' => 'Title',
      'dokumenttyp' => 'Document type term ID',
      'maschinentypen' => 'Machine type term IDs',
      'seriennummer' => 'Machine node ID (optional)',
      'exportklasse' => 'Export class',
      'freigegeben_am' => 'Release date (Y-m-d)',
      'gueltig_bis' => 'Valid until (Y-m-d, optional)',
      'files' => 'Files per language: langcode, datei, aenderungsvermerk',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return int[]
   *   Node-ID und Revisions-ID.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $manifestId = (string) $row->getDestinationProperty('id');
    $version = (string) $row->getDestinationProperty('version');
    $files = array_values((array) $row->getDestinationProperty('files'));
    if ($manifestId === '' || $version === '' || $files === []) {
      throw new MigrateException('Document row needs id, version and at least one file.');
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ownerId = $this->accountId((string) ($this->configuration['default_author'] ?? 'redaktion'));
    $authorId = $this->accountId($this->revisionAuthor($manifestId, $version));

    // Bestehende Revision dieser Zeile (Aktualisierung an Ort und Stelle) oder
    // der Node derselben Manifest-ID (neue Revision) oder ein neuer Node.
    $node = NULL;
    $inPlace = FALSE;
    if (!empty($old_destination_id_values[1])) {
      $revision = $storage->loadRevision((int) $old_destination_id_values[1]);
      if ($revision instanceof NodeInterface) {
        $node = $revision;
        $inPlace = TRUE;
      }
    }
    if ($node === NULL) {
      foreach ($this->migration->getIdMap()->lookupDestinationIds([$manifestId]) as $ids) {
        $existing = empty($ids[0]) ? NULL : $storage->load((int) $ids[0]);
        if ($existing instanceof NodeInterface) {
          $node = $existing;
          break;
        }
      }
    }
    $releaseTime = $this->timestamp((string) $row->getDestinationProperty('freigegeben_am'));
    if ($node === NULL) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $storage->create([
        'type' => 'dokument',
        'langcode' => $files[0]['langcode'],
        'uid' => $ownerId,
        'created' => $releaseTime,
      ]);
    }
    $node->setSyncing(TRUE);
    $node->setNewRevision(!$inPlace && !$node->isNew());
    if (!$inPlace) {
      $node->isDefaultRevision(TRUE);
    }

    // Nicht übersetzbare Felder am Original.
    $node->set('field_dokumenttyp', $row->getDestinationProperty('dokumenttyp'));
    $node->set('field_maschinentyp', array_values((array) $row->getDestinationProperty('maschinentypen')));
    $node->set('field_seriennummer', $row->getDestinationProperty('seriennummer') ?: NULL);
    $node->set('field_version', $version);
    $node->set('field_exportklasse', (string) ($row->getDestinationProperty('exportklasse') ?: 'keine'));
    $node->set('field_freigegeben_am', $row->getDestinationProperty('freigegeben_am') ?: NULL);
    $node->set('field_gueltig_bis', $row->getDestinationProperty('gueltig_bis') ?: NULL);

    $langcodes = [];
    foreach ($files as $file) {
      $langcode = (string) $file['langcode'];
      $langcodes[] = $langcode;
      $translation = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node->addTranslation($langcode);
      $translation->set('title', (string) $row->getDestinationProperty('titel'));
      $translation->set('body', [
        'value' => $this->fixtureContent((string) $file['datei']),
        'format' => (string) ($this->configuration['text_format'] ?? 'dokument_html'),
      ]);
      $translation->set('field_aenderungsvermerk', (string) $file['aenderungsvermerk']);
      $translation->set('field_datei', ['target_id' => $this->fileId((string) $file['datei'], $ownerId)]);
      $translation->set('moderation_state', 'published');
      $translation->setPublished();
      $translation->setChangedTime($releaseTime);
    }
    if (!in_array($node->getUntranslated()->language()->getId(), $langcodes, TRUE)) {
      throw new MigrateException(sprintf('%s %s lacks the original language %s.', $manifestId, $version, $node->getUntranslated()->language()->getId()));
    }
    // Sprachen, die diese Version nicht mehr hat, fallen in der Revision weg.
    foreach (array_keys($node->getTranslationLanguages(FALSE)) as $langcode) {
      if (!in_array($langcode, $langcodes, TRUE)) {
        $node->removeTranslation($langcode);
      }
    }
    $node->setRevisionUserId($authorId);
    $node->setRevisionCreationTime($releaseTime);
    $node->setRevisionLogMessage(sprintf('%s %s', $manifestId, $version));
    $node->save();
    return [(int) $node->id(), (int) $node->getRevisionId()];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $nid = (int) ($destination_identifier['nid'] ?? reset($destination_identifier));
    $node = $nid > 0 ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    $node?->delete();
  }

  /**
   * Revisionsautor einer Version (Konto-Name).
   */
  protected function revisionAuthor(string $manifestId, string $version): string {
    foreach ($this->configuration['revision_authors'] ?? [] as $entry) {
      if (($entry['id'] ?? NULL) === $manifestId && (string) ($entry['version'] ?? '') === $version) {
        return (string) $entry['user'];
      }
    }
    return (string) ($this->configuration['default_author'] ?? 'redaktion');
  }

  /**
   * Nutzer-ID eines Demo-Kontos; ohne Konto scheitert die Zeile.
   */
  protected function accountId(string $accountName): int {
    $uid = $this->lookup->uidOf($accountName);
    if ($uid === NULL) {
      throw new MigrateException(sprintf('Account %s is missing (tannwald_users runs before tannwald_dokumente).', $accountName));
    }
    return $uid;
  }

  /**
   * Unix-Zeit 08:00 Zürich eines Manifest-Datums (ohne Datum: jetzt).
   */
  protected function timestamp(string $date): int {
    if ($date === '') {
      return $this->time->getRequestTime();
    }
    return (new \DateTimeImmutable($date . ' 08:00:00', new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
  }

  /**
   * Absoluter Pfad einer Fixture-Datei.
   */
  protected function fixturePath(string $name): string {
    $path = FixturePath::resolve(trim((string) ($this->configuration['fixture_directory'] ?? 'dokumente'), '/') . '/' . basename($name));
    if (!is_file($path) || !is_readable($path)) {
      throw new MigrateException(sprintf('Fixture file %s is missing.', $path));
    }
    return $path;
  }

  /**
   * HTML-Inhalt einer Fixture-Datei.
   */
  protected function fixtureContent(string $name): string {
    return trim((string) file_get_contents($this->fixturePath($name)));
  }

  /**
   * File-Entity unter private://dokumente/<datei>, wiederverwendet je URI.
   *
   * Die Datei wird nur kopiert, wenn sie fehlt oder sich unterscheidet; die
   * Entity bleibt dauerhaft, file_usage schreibt das Feld beim Speichern.
   */
  protected function fileId(string $name, int $ownerId): int {
    $source = $this->fixturePath($name);
    $directory = rtrim((string) ($this->configuration['file_directory'] ?? 'private://dokumente'), '/');
    $uri = $directory . '/' . basename($name);
    if (!is_file($uri) || md5_file($uri) !== md5_file($source)) {
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new MigrateException(sprintf('Directory %s is not writable (file_private_path set?).', $directory));
      }
      $this->fileSystem->copy($source, $uri, FileExists::Replace);
    }
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $existing = $fileStorage->loadByProperties(['uri' => $uri]);
    $file = $existing === [] ? NULL : reset($existing);
    if (!$file instanceof FileInterface) {
      $file = $fileStorage->create([
        'uri' => $uri,
        'filename' => basename($name),
        'filemime' => 'text/html',
        'uid' => $ownerId,
      ]);
    }
    if ($file->isNew() || !$file->isPermanent() || (int) $file->getSize() !== (int) filesize($uri)) {
      $file->setPermanent();
      $file->setSize((int) filesize($uri));
      $file->save();
    }
    return (int) $file->id();
  }

}
