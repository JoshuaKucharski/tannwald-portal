<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Eindeutig je Nutzer, Bulletin und Maschine (DB-Index).
 */
class LesebestaetigungStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * Name des Unique-Index.
   */
  public const UNIQUE_KEY = 'lesebestaetigung__uid_bulletin_maschine';

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $this->storage->getBaseTable();
    $schema[$table]['unique keys'][self::UNIQUE_KEY] = ['uid', 'bulletin_nid', 'maschine_nid'];
    return $schema;
  }

}
