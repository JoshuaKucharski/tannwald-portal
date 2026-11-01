<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Hook;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tannwald_bulletin\Entity\LesebestaetigungInterface;

/**
 * Hooks von tannwald_bulletin.
 */
final class BulletinHooks {

  /**
   * Präfix des Idempotenz-Eintrags im State: <präfix><nid>.<vid>.
   */
  public const NOTIFIED_STATE_PREFIX = 'tannwald_bulletin.notified.';

  /**
   * Status vor dem Speichern, je Node-Objekt.
   *
   * @var array<int, string|null>
   */
  private array $previousStates = [];

  public function __construct(
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() für lesebestaetigung.
   *
   * Zweite Schranke neben dem Access-Handler: eine gespeicherte
   * Lesebestätigung ändert sich nie, auch nicht über Code.
   */
  #[Hook('lesebestaetigung_presave')]
  public function lesebestaetigungPresave(LesebestaetigungInterface $confirmation): void {
    if (!$confirmation->isNew()) {
      throw new \LogicException(sprintf('Read confirmation %s is immutable.', $confirmation->id()));
    }
  }

  /**
   * Implements hook_node_presave().
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if ($node->bundle() !== 'bulletin') {
      return;
    }
    $this->previousStates[spl_object_id($node)] = $node->isNew() ? NULL : $this->originalState($node);
  }

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->queueNotification($node);
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->queueNotification($node);
  }

  /**
   * Implements hook_views_data().
   *
   * @return array<string, mixed>
   *   Views-Daten: Area "offene Lesebestätigungen".
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data['views']['tannwald_bulletin_open_confirmations'] = [
      'title' => new TranslatableMarkup('Open read confirmations'),
      'help' => new TranslatableMarkup('Affected users of the bulletin in the first argument who have not confirmed yet.'),
      'area' => ['id' => 'tannwald_bulletin_open_confirmations'],
    ];
    return $data;
  }

  /**
   * Stellt einen Mail-Job ein: Übergang nach published mit Bestätigungspflicht.
   */
  private function queueNotification(NodeInterface $node): void {
    $objectId = spl_object_id($node);
    if (!array_key_exists($objectId, $this->previousStates)) {
      return;
    }
    $from = $this->previousStates[$objectId];
    unset($this->previousStates[$objectId]);
    if ($this->currentState($node) !== 'published' || $from === 'published') {
      return;
    }
    if (!$node->hasField('field_bestaetigung_pflicht') || !$node->get('field_bestaetigung_pflicht')->value) {
      // Ohne Bestätigungspflicht keine Mail (Master-Präzisierung 2.5); den
      // Hinweis im Portal zeigt das Theme für alle Bulletins.
      return;
    }
    $key = self::NOTIFIED_STATE_PREFIX . $node->id() . '.' . $node->getRevisionId();
    if ($this->state->get($key) !== NULL) {
      return;
    }
    $this->state->set($key, time());
    $this->queueFactory->get('tannwald_notify')->createItem(['bulletin_nid' => (int) $node->id()]);
  }

  /**
   * Status im Zielzustand.
   */
  private function currentState(NodeInterface $node): string {
    if ($this->moderationInformation->isModeratedEntity($node) && !$node->get('moderation_state')->isEmpty()) {
      return (string) $node->get('moderation_state')->value;
    }
    return $node->isPublished() ? 'published' : 'unpublished';
  }

  /**
   * Status vor dem Speichern.
   */
  private function originalState(NodeInterface $node): ?string {
    if ($this->moderationInformation->isModeratedEntity($node)) {
      return $this->moderationInformation->getOriginalState($node)->id();
    }
    $original = $node->getOriginal();
    if (!$original instanceof NodeInterface) {
      return NULL;
    }
    return $original->isPublished() ? 'published' : 'unpublished';
  }

}
