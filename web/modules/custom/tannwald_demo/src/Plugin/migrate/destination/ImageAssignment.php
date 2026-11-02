<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Plugin\migrate\destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\FileInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\tannwald_demo\DemoInstaller;
use Drupal\tannwald_demo\DemoLookup;
use Drupal\tannwald_demo\FixturePath;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hängt ein Bild aus fixtures/bilder an ein Ziel (Term oder Node).
 *
 * Je Quellzeile (datei, ziel): Datei nach public://bilder/<datei> kopieren
 * (nur wenn sie fehlt oder abweicht), File-Entity je URI wiederverwenden und
 * dauerhaft halten, field_bild am Ziel setzen (deutscher Alt-Text). Das Ziel
 * wird ohne neue Revision und als "syncing" gespeichert: kein neues
 * changed-Datum, keine Revision, kein Moderationswechsel. Ist das Feld schon
 * gleich, wird nicht gespeichert. Rollback leert nur das Feld; Datei und
 * File-Entity bleiben (demo-reset spielt sie neu ein, die Usage entsteht neu).
 *
 * Embed-Jobs: tannwald_ingest reiht bei jedem Node-Update Jobs ein. Ein Bild
 * ändert weder Titel noch Text noch Zugriffsschlüssel, die Chunks bleiben
 * gleich; die Jobs, die genau dieser Save für diesen Node eingereiht hat,
 * werden wieder entfernt (sonst bettet der Seeder ohne Grund neu ein).
 *
 * Konfiguration:
 * - directory: Ziel der Dateien (public://bilder).
 * - fixture_directory: Unterordner von fixtures/ (bilder).
 * - field: Bildfeld (field_bild).
 */
#[MigrateDestination('tannwald_image')]
final class ImageAssignment extends DestinationBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected DemoLookup $lookup,
    protected Connection $database,
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
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'entity_type' => ['type' => 'string', 'max_length' => 32, 'is_ascii' => TRUE],
      'entity_id' => ['type' => 'integer', 'unsigned' => TRUE],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(?MigrationInterface $migration = NULL) {
    return [
      'purpose' => 'typ, dokumenttyp, maschine or dokument',
      'target' => 'Code, slug, serial number or manifest ID',
      'file' => 'File name in fixtures/bilder',
      'alt' => 'Alt texts keyed by langcode',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array{0: string, 1: int}
   *   Entity-Typ und ID des Ziels.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $purpose = (string) $row->getDestinationProperty('purpose');
    $target = (string) $row->getDestinationProperty('target');
    $name = basename((string) $row->getDestinationProperty('file'));
    $alt = (array) $row->getDestinationProperty('alt');
    $entity = $this->lookup->imageTarget($purpose, $target);
    if ($entity === NULL) {
      throw new MigrateException(sprintf('Image %s: target %s "%s" not found.', $name, $purpose, $target));
    }
    $altDe = trim((string) ($alt['de'] ?? ''));
    if ($altDe === '') {
      throw new MigrateException(sprintf('Image %s: alt text (de) missing.', $name));
    }
    $fid = $this->fileId($name);
    $field = $this->field();
    $current = $entity->get($field)->first()?->getValue() ?? [];
    if ((int) ($current['target_id'] ?? 0) !== $fid || (string) ($current['alt'] ?? '') !== $altDe) {
      $entity->set($field, ['target_id' => $fid, 'alt' => $altDe]);
      $this->saveQuietly($entity);
    }
    return [$entity->getEntityTypeId(), (int) $entity->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $entityTypeId = (string) ($destination_identifier['entity_type'] ?? '');
    $id = (int) ($destination_identifier['entity_id'] ?? 0);
    if ($entityTypeId === '' || $id === 0 || !$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return;
    }
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($id);
    if ($entity instanceof FieldableEntityInterface && $entity->hasField($this->field()) && !$entity->get($this->field())->isEmpty()) {
      $entity->set($this->field(), NULL);
      $this->saveQuietly($entity);
    }
  }

  /**
   * Speichert ohne neue Revision und ohne neues changed-Datum.
   */
  protected function saveQuietly(FieldableEntityInterface $entity): void {
    if ($entity instanceof RevisionableInterface) {
      $entity->setNewRevision(FALSE);
    }
    if ($entity instanceof SynchronizableInterface) {
      $entity->setSyncing(TRUE);
    }
    $queue = DemoInstaller::INGEST_QUEUES['embed'];
    $tableExists = $this->database->schema()->tableExists('queue');
    $before = $tableExists ? (int) $this->database->query('SELECT MAX(item_id) FROM {queue}')->fetchField() : 0;
    $entity->save();
    if (!$tableExists || $entity->getEntityTypeId() !== 'node') {
      return;
    }
    $items = $this->database->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('name', $queue)
      ->condition('item_id', $before, '>')
      ->execute()
      ->fetchAllKeyed();
    $own = [];
    foreach ($items as $itemId => $data) {
      $job = unserialize((string) $data, ['allowed_classes' => FALSE]);
      if (is_array($job) && (int) ($job['nid'] ?? 0) === (int) $entity->id()) {
        $own[] = (int) $itemId;
      }
    }
    if ($own !== []) {
      $this->database->delete('queue')->condition('item_id', $own, 'IN')->execute();
    }
  }

  /**
   * Name des Bildfelds.
   */
  protected function field(): string {
    return (string) ($this->configuration['field'] ?? DemoLookup::IMAGE_FIELD);
  }

  /**
   * File-Entity unter public://bilder/<datei>, wiederverwendet je URI.
   */
  protected function fileId(string $name): int {
    $source = FixturePath::resolve(trim((string) ($this->configuration['fixture_directory'] ?? 'bilder'), '/') . '/' . $name);
    if (!is_file($source) || !is_readable($source)) {
      throw new MigrateException(sprintf('Fixture image %s is missing.', $source));
    }
    $directory = rtrim((string) ($this->configuration['directory'] ?? 'public://bilder'), '/');
    $uri = $directory . '/' . $name;
    if (!is_file($uri) || md5_file($uri) !== md5_file($source)) {
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new MigrateException(sprintf('Directory %s is not writable.', $directory));
      }
      $this->fileSystem->copy($source, $uri, FileExists::Replace);
    }
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $existing = $fileStorage->loadByProperties(['uri' => $uri]);
    $file = $existing === [] ? NULL : reset($existing);
    if (!$file instanceof FileInterface) {
      $file = $fileStorage->create([
        'uri' => $uri,
        'filename' => $name,
        'filemime' => self::mimeOf($name),
        'uid' => 1,
      ]);
    }
    // Dauerhaft: ohne Usage (nach einem Rollback) räumt Cron sonst nichts weg,
    // weil nur temporäre Dateien gelöscht werden.
    if ($file->isNew() || !$file->isPermanent() || (int) $file->getSize() !== (int) filesize($uri)) {
      $file->setPermanent();
      $file->setSize((int) filesize($uri));
      $file->save();
    }
    return (int) $file->id();
  }

  /**
   * MIME-Typ nach Endung (webp, jpg, png).
   */
  public static function mimeOf(string $name): string {
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
      'jpg', 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      default => 'image/webp',
    };
  }

}
