<?php

declare(strict_types=1);

namespace Drupal\tannwald_access;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Vier-Augen-Prinzip für Sicherheits-Bulletins (FA-RED-01).
 *
 * Wer die erste Revision eines Bulletins mit field_bulletin_art = sicherheit
 * erstellt hat, darf es nicht nach "published" überführen. Genutzt von der
 * Constraint NotOwnBulletinApproval (Entity-Validierung, Bearbeitungsformular)
 * und vom Moderationsformular, das keine Entity-Validierung aufruft.
 */
final class BulletinApprovalGuard {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * Prüft, ob der Übergang eine unzulässige Selbstfreigabe ist.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Der Node im Zielzustand (noch nicht gespeichert).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Wer freigibt.
   */
  public function isOwnApproval(NodeInterface $node, AccountInterface $account): bool {
    if ($node->bundle() !== 'bulletin' || !$node->hasField('field_bulletin_art')) {
      return FALSE;
    }
    if ($node->get('field_bulletin_art')->value !== 'sicherheit') {
      return FALSE;
    }
    if (!$this->isTransitionToPublished($node)) {
      return FALSE;
    }
    return in_array((int) $account->id(), $this->creatorIds($node), TRUE);
  }

  /**
   * Ob der Node gerade nach "published" wechselt.
   */
  private function isTransitionToPublished(NodeInterface $node): bool {
    if ($this->moderationInformation->isModeratedEntity($node) && $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $to = (string) $node->get('moderation_state')->value;
      $from = $this->moderationInformation->getOriginalState($node)->id();
      return $to === 'published' && $from !== 'published';
    }
    // Ohne Workflow: Statuswechsel auf veröffentlicht.
    if (!$node->isPublished()) {
      return FALSE;
    }
    if ($node->isNew()) {
      return TRUE;
    }
    $original = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    return !$original instanceof NodeInterface || !$original->isPublished();
  }

  /**
   * Ersteller: Eigentümer und Revisionsautor der ersten Revision.
   *
   * @return int[]
   *   Nutzer-IDs.
   */
  private function creatorIds(NodeInterface $node): array {
    $first = $node;
    if (!$node->isNew()) {
      $storage = $this->entityTypeManager->getStorage('node');
      $vids = $storage->getQuery()
        ->allRevisions()
        ->accessCheck(FALSE)
        ->condition('nid', $node->id())
        ->sort('vid')
        ->range(0, 1)
        ->execute();
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
      $revision = $vids === [] ? NULL : $storage->loadRevision((int) array_key_first($vids));
      if ($revision instanceof NodeInterface) {
        $first = $revision;
      }
    }
    $ids = [(int) $first->getOwnerId()];
    $revisionUser = $first->getRevisionUserId();
    if ($revisionUser !== NULL) {
      $ids[] = (int) $revisionUser;
    }
    return array_values(array_unique(array_filter($ids)));
  }

}
