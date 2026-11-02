<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\Hook;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\AuditLogger;
use Drupal\tannwald_access\EntitlementService;

/**
 * Hooks von tannwald_access.
 */
final class AccessHooks {

  /**
   * Präfix der geschützten Dokumentdateien.
   */
  public const DOCUMENT_URI_PREFIX = 'private://dokumente/';

  /**
   * Bundles, deren Freigabe ins Audit geht.
   */
  private const AUDITED_BUNDLES = ['dokument', 'bulletin', 'zusammenfassung'];

  /**
   * Status vor dem Speichern, je Node-Objekt (presave → insert/update).
   *
   * @var array<int, string|null>
   */
  private array $previousStates = [];

  public function __construct(
    private readonly EntitlementService $entitlement,
    private readonly AuditLogger $audit,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly FileUsageInterface $fileUsage,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_node_grants().
   *
   * @return array<string, int[]>
   *   Realm => gids.
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, string $operation): array {
    // Grants geben nur Sicht; Bearbeiten und Löschen bleiben Permissions.
    if ($operation !== 'view') {
      return [];
    }
    return $this->entitlement->getGrantsForAccount($account);
  }

  /**
   * Implements hook_node_access_records().
   *
   * @return array<int, array<string, int|string>>
   *   Records für dokument, maschine, zusammenfassung.
   */
  #[Hook('node_access_records')]
  public function nodeAccessRecords(NodeInterface $node): array {
    if (!in_array($node->bundle(), ['dokument', 'maschine', 'zusammenfassung'], TRUE)) {
      return [];
    }
    return $this->entitlement->getAccessRecordsForNode($node);
  }

  /**
   * Implements hook_node_access_records_alter().
   *
   * Bulletins erhalten ihre Records hier (Pflichtenheft §6.1).
   *
   * @param array<int, array<string, int|string>> $grants
   *   Records aller Module.
   * @param \Drupal\node\NodeInterface $node
   *   Der Node.
   */
  #[Hook('node_access_records_alter')]
  public function nodeAccessRecordsAlter(array &$grants, NodeInterface $node): void {
    if ($node->bundle() === 'bulletin') {
      $grants = array_merge($grants, $this->entitlement->getAccessRecordsForNode($node));
    }
  }

  /**
   * Implements hook_file_download().
   *
   * Zweite Schranke für rohe /system/files/dokumente/…-Adressen: Header nur
   * mit Sicht auf einen referenzierenden Node, sonst -1 und Audit.
   *
   * @return array<string, string>|int|null
   *   Header, -1 oder NULL (nicht zuständig).
   */
  #[Hook('file_download')]
  public function fileDownload(string $uri): array|int|null {
    if (!str_starts_with($uri, self::DOCUMENT_URI_PREFIX)) {
      return NULL;
    }
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = reset($files);
    if (!$file instanceof FileInterface) {
      return -1;
    }
    $nodes = $this->referencingNodes($file);
    foreach ($nodes as $node) {
      if ($node->access('view', $this->currentUser)) {
        return $file->getDownloadHeaders();
      }
    }
    $first = reset($nodes);
    if ($first instanceof NodeInterface) {
      $this->audit->log('zugriff_verweigert', $first, [
        'route' => 'system.files',
        'nid' => (int) $first->id(),
        'fid' => (int) $file->id(),
      ]);
    }
    else {
      $this->audit->log('zugriff_verweigert', $file, [
        'route' => 'system.files',
        'nid' => NULL,
        'fid' => (int) $file->id(),
      ]);
    }
    return -1;
  }

  /**
   * Implements hook_entity_type_alter().
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $entity_types
   *   Entity-Typen.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['node'])) {
      $entity_types['node']->addConstraint('NotOwnBulletinApproval');
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() für das Moderationsformular.
   *
   * Das Formular "Moderationsstatus ändern" ruft keine Entity-Validierung auf;
   * die Vier-Augen-Prüfung hängt sich deshalb als Validierung an.
   *
   * @param array<string, mixed> $form
   *   Formular.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Formularstatus.
   */
  #[Hook('form_content_moderation_entity_moderation_form_alter')]
  public function moderationFormAlter(array &$form, FormStateInterface $form_state): void {
    // Statischer Callback: das Formular kann serialisiert werden.
    $form['#validate'][] = [self::class, 'validateModerationForm'];
  }

  /**
   * Validiert das Moderationsformular gegen das Vier-Augen-Prinzip.
   *
   * @param array<string, mixed> $form
   *   Formular.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Formularstatus.
   */
  public static function validateModerationForm(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->get('entity');
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $candidate = clone $entity;
    $candidate->set('moderation_state', $form_state->getValue('new_state'));
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    if (\Drupal::service('tannwald_access.bulletin_approval_guard')->isOwnApproval($candidate, \Drupal::currentUser())) {
      $form_state->setErrorByName('new_state', t('Safety bulletins must not be approved by the person who created them (four-eyes principle).'));
    }
  }

  /**
   * Implements hook_node_presave().
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if (!in_array($node->bundle(), self::AUDITED_BUNDLES, TRUE)) {
      return;
    }
    $this->previousStates[spl_object_id($node)] = $node->isNew() ? NULL : $this->originalState($node);
  }

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->auditApproval($node);
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->auditApproval($node);
  }

  /**
   * Schreibt Audit "freigabe" beim Übergang nach published.
   */
  private function auditApproval(NodeInterface $node): void {
    $objectId = spl_object_id($node);
    if (!array_key_exists($objectId, $this->previousStates)) {
      return;
    }
    $from = $this->previousStates[$objectId];
    unset($this->previousStates[$objectId]);
    $to = $this->currentState($node);
    if ($to === 'published' && $from !== 'published') {
      $this->audit->log('freigabe', $node, [
        'von' => $from,
        'nach' => $to,
        'revision' => (int) $node->getRevisionId(),
      ]);
    }
  }

  /**
   * Status des Nodes im Zielzustand.
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

  /**
   * Nodes, die eine Datei referenzieren (file_usage, sonst field_datei).
   *
   * @return \Drupal\node\NodeInterface[]
   *   Nodes.
   */
  private function referencingNodes(FileInterface $file): array {
    $usage = $this->fileUsage->listUsage($file);
    $nids = [];
    foreach ($usage as $types) {
      foreach (array_keys($types['node'] ?? []) as $nid) {
        $nids[] = (int) $nid;
      }
    }
    if ($nids === []) {
      try {
        $nids = array_map('intval', array_values($this->entityTypeManager->getStorage('node')->getQuery()
          ->accessCheck(FALSE)
          ->allRevisions()
          ->condition('field_datei', $file->id())
          ->execute()));
      }
      catch (\Exception) {
        // Ohne field_datei keine Zuordnung: verweigern (fail closed).
        $nids = [];
      }
    }
    if ($nids === []) {
      return [];
    }
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_unique($nids));
    return $nodes;
  }

}
