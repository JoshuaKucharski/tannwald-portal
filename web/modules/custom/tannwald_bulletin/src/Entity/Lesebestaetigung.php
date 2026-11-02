<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tannwald_bulletin\LesebestaetigungAccessControlHandler;
use Drupal\tannwald_bulletin\LesebestaetigungStorageSchema;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Lesebestätigung (Pflichtenheft §5.4, FA-DP-04), unveränderbar.
 *
 * Kein Formular, keine Routen; Update und Delete verweigert der
 * Access-Handler, ein Speichern einer bestehenden Zeile verhindert
 * BulletinHooks::lesebestaetigungPresave().
 */
#[ContentEntityType(
  id: 'lesebestaetigung',
  label: new TranslatableMarkup('Read confirmation'),
  label_collection: new TranslatableMarkup('Read confirmations'),
  label_singular: new TranslatableMarkup('read confirmation'),
  label_plural: new TranslatableMarkup('read confirmations'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => LesebestaetigungAccessControlHandler::class,
    'storage_schema' => LesebestaetigungStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  base_table: 'lesebestaetigung',
  constraints: ['UniqueReadConfirmation' => []],
)]
class Lesebestaetigung extends ContentEntityBase implements LesebestaetigungInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    if ($fields['uid'] instanceof BaseFieldDefinition) {
      $fields['uid']->setLabel(new TranslatableMarkup('User'))->setRequired(TRUE);
    }

    $fields['bulletin_nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Bulletin'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', ['target_bundles' => ['bulletin' => 'bulletin']])
      ->setRequired(TRUE);

    $fields['maschine_nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Machine'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', ['target_bundles' => ['maschine' => 'maschine']])
      ->setRequired(TRUE);

    $fields['bestaetigt_am'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Confirmed on'))
      ->setRequired(TRUE);

    $fields['ip_land'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Country'))
      ->setDescription(new TranslatableMarkup('PoC: field_land of the user, no GeoIP.'))
      ->setSetting('max_length', 2);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getBulletinId(): int {
    return (int) $this->get('bulletin_nid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineId(): int {
    return (int) $this->get('maschine_nid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmedTime(): int {
    return (int) $this->get('bestaetigt_am')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCountry(): ?string {
    $value = $this->get('ip_land')->value;
    return $value === NULL || $value === '' ? NULL : (string) $value;
  }

}
