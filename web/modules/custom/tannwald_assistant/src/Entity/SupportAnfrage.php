<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tannwald_assistant\SupportAnfrageAccessControlHandler;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Support-Anfrage aus dem Assistenten (Pflichtenheft §5.4, FA-AI-03).
 *
 * Ersetzt im PoC das Salesforce-Ticket; angelegt nur über
 * POST /api/assistant/support, weitergereicht an TicketSinkInterface.
 */
#[ContentEntityType(
  id: 'support_anfrage',
  label: new TranslatableMarkup('Support request'),
  label_collection: new TranslatableMarkup('Support requests'),
  label_singular: new TranslatableMarkup('support request'),
  label_plural: new TranslatableMarkup('support requests'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => SupportAnfrageAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
  ],
  base_table: 'support_anfrage',
)]
class SupportAnfrage extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const STATUS_OPEN = 'offen';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    if ($fields['uid'] instanceof BaseFieldDefinition) {
      $fields['uid']->setLabel(new TranslatableMarkup('User'))->setRequired(TRUE);
    }

    $fields['maschine_nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Machine'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', ['target_bundles' => ['maschine' => 'maschine']]);

    $fields['frage'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setRequired(TRUE);

    $fields['verlauf'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('History (JSON)'))
      ->setDescription(new TranslatableMarkup('Up to five assistant log entries of the same session: question, status, answer, time.'));

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setSetting('max_length', 32)
      ->setDefaultValue(self::STATUS_OPEN)
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

  /**
   * Verlauf als Liste {frage, status, antwort, zeit}.
   *
   * @return array<int, array<string, mixed>>
   *   Einträge, ältester zuerst.
   */
  public function getHistory(): array {
    $decoded = json_decode((string) $this->get('verlauf')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Maschine (nid) oder NULL.
   */
  public function getMachineId(): ?int {
    $field = $this->get('maschine_nid');
    return $field->isEmpty() ? NULL : (int) $field->target_id;
  }

}
