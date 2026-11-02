<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker;
use Drupal\tannwald_ingest\Store\ChunkStoreInterface;

/**
 * Node-Hooks: stellen Jobs in tannwald_embed ein, rechnen nie Embeddings.
 */
class IngestHooks {

  public function __construct(
    protected QueueFactory $queueFactory,
    protected ChunkStoreInterface $store,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for node.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->enqueueRevision($node);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for node.
   *
   * Feuert auch bei Übersetzungs-Speicherungen und Moderationswechseln ohne
   * neue Revision; die Jobs sind idempotent.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    if (!in_array($node->bundle(), EmbedQueueWorker::BUNDLES, TRUE)) {
      return;
    }
    $this->enqueueRevision($node);
    if (!$node->isDefaultRevision()) {
      return;
    }
    // Standard-Revision ohne veröffentlichte Übersetzung (archiviert, zurück
    // auf Entwurf): alle Chunks des Nodes löschen, auch die älterer
    // veröffentlichter Revisionen — sonst blieben sie mit gültigen Keys
    // auffindbar.
    $anyPublished = FALSE;
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $anyPublished = $anyPublished || $node->getTranslation($langcode)->isPublished();
    }
    if (!$anyPublished) {
      $this->queueFactory->get(EmbedQueueWorker::QUEUE)->createItem(['nid' => (int) $node->id(), 'action' => 'delete']);
      return;
    }
    // Einzelne Übersetzung unveröffentlicht: Chunks der bisher
    // veröffentlichten Revision dieser Sprache löschen.
    $original = $node->getOriginal();
    if (!$original instanceof NodeInterface) {
      return;
    }
    $originalVid = (int) $original->getRevisionId();
    if ($originalVid === (int) $node->getRevisionId()) {
      return;
    }
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      if ($original->hasTranslation($langcode)
        && $original->getTranslation($langcode)->isPublished()
        && !$node->getTranslation($langcode)->isPublished()) {
        $this->enqueue((int) $node->id(), $originalVid, $langcode, 'delete');
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for node.
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    if (in_array($node->bundle(), EmbedQueueWorker::BUNDLES, TRUE)) {
      // Löschen ist billig und muss sofort wirken (kein Embedding).
      $this->store->deleteForNode((int) $node->id());
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_revision_delete() for node.
   */
  #[Hook('node_revision_delete')]
  public function nodeRevisionDelete(NodeInterface $node): void {
    if (in_array($node->bundle(), EmbedQueueWorker::BUNDLES, TRUE)) {
      $this->store->deleteForRevision((int) $node->id(), (int) $node->getRevisionId());
    }
  }

  /**
   * Je Übersetzung der neuen Revision ein Job: index oder delete.
   */
  protected function enqueueRevision(NodeInterface $node): void {
    if (!in_array($node->bundle(), EmbedQueueWorker::BUNDLES, TRUE)) {
      return;
    }
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $action = $node->getTranslation($langcode)->isPublished() ? 'index' : 'delete';
      $this->enqueue((int) $node->id(), (int) $node->getRevisionId(), $langcode, $action);
    }
  }

  /**
   * Stellt einen Job ein.
   */
  protected function enqueue(int $nid, int $vid, string $langcode, string $action): void {
    $this->queueFactory->get(EmbedQueueWorker::QUEUE)->createItem([
      'nid' => $nid,
      'vid' => $vid,
      'langcode' => $langcode,
      'action' => $action,
    ]);
  }

}
