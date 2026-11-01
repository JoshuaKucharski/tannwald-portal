<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Xss;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Führt eine Review-Transition an einer Zusammenfassung aus.
 *
 * Speichert eine neue Revision mit dem Zielstatus; die Folgen (Index über
 * tannwald_embed, Löschen der Chunks, Audit „freigabe“ von tannwald_access)
 * entstehen in den Node-Hooks bei dieser Speicherung.
 */
class ReviewTransition {

  /**
   * Transition => Zielstatus im Workflow redaktion.
   */
  public const TRANSITIONS = [
    'freigeben' => 'published',
    'zurueckweisen' => 'zurueckgewiesen',
    'entwurf_speichern' => 'draft',
  ];

  /**
   * Erlaubte Tags im bearbeiteten Text.
   */
  public const ALLOWED_TAGS = ['p', 'ul', 'ol', 'li', 'strong', 'em'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModerationInformationInterface $moderationInformation,
    protected TimeInterface $time,
  ) {}

  /**
   * Neueste Revision der Übersetzung (auch eine noch nicht freigegebene).
   */
  public function latest(NodeInterface $node, ?string $langcode = NULL): NodeInterface {
    $langcode = $langcode ?: $node->language()->getId();
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $vid = $storage->getLatestTranslationAffectedRevisionId($node->id(), $langcode) ?? $storage->getLatestRevisionId($node->id());
    $revision = $vid ? $storage->loadRevision($vid) : NULL;
    if (!$revision instanceof NodeInterface) {
      $revision = $node;
    }
    if (!$revision->hasTranslation($langcode)) {
      throw new BadRequestHttpException(sprintf('Summary %d has no translation %s.', $node->id(), $langcode));
    }
    return $revision->getTranslation($langcode);
  }

  /**
   * Transitionen, die $account an der neuesten Revision jetzt ausführen darf.
   *
   * @return string[]
   *   Teilmenge der Schlüssel von TRANSITIONS.
   */
  public function allowed(NodeInterface $node, AccountInterface $account, ?string $langcode = NULL): array {
    if ($node->bundle() !== 'zusammenfassung') {
      return [];
    }
    $latest = $this->latest($node, $langcode);
    return array_values(array_filter(array_keys(self::TRANSITIONS), fn(string $t): bool => $this->isValid($latest, $t) && $account->hasPermission("use redaktion transition $t")));
  }

  /**
   * Führt die Transition aus.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Zusammenfassung (beliebige Revision).
   * @param string $transition
   *   Transition: freigeben|zurueckweisen|entwurf_speichern.
   * @param string|null $body
   *   Bearbeiteter Text (HTML) oder NULL/leer für unverändert.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Handelnde Person.
   * @param string|null $langcode
   *   Sprache der Übersetzung (Standard: Sprache von $node).
   *
   * @return array
   *   {nid, vid, moderation_state, bearbeitet: bool}.
   */
  public function apply(NodeInterface $node, string $transition, ?string $body, AccountInterface $account, ?string $langcode = NULL): array {
    if ($node->bundle() !== 'zusammenfassung') {
      throw new BadRequestHttpException('Only summaries can be reviewed here.');
    }
    if (!isset(self::TRANSITIONS[$transition])) {
      throw new BadRequestHttpException(sprintf('Unknown transition %s.', $transition));
    }
    if (!$account->hasPermission("use redaktion transition $transition")) {
      throw new AccessDeniedHttpException(sprintf('Transition %s is not allowed for this account.', $transition));
    }
    $latest = $this->latest($node, $langcode);
    if (!$this->isValid($latest, $transition)) {
      throw new BadRequestHttpException(sprintf('Transition %s is not possible from state %s.', $transition, $latest->get('moderation_state')->value));
    }
    $edited = FALSE;
    if ($body !== NULL && trim($body) !== '') {
      $clean = trim(Xss::filter($body, self::ALLOWED_TAGS));
      if ($clean !== trim((string) $latest->get('body')->value)) {
        $latest->set('body', ['value' => $clean, 'format' => 'dokument_html']);
        $edited = TRUE;
      }
    }
    $latest->setNewRevision(TRUE);
    $latest->set('moderation_state', self::TRANSITIONS[$transition]);
    $latest->setRevisionUserId((int) $account->id());
    $latest->setRevisionCreationTime($this->time->getCurrentTime());
    $latest->setRevisionLogMessage(sprintf('%s durch %s', $transition, $account->getDisplayName()));
    // Der Moderationsstatus ist berechnet: ohne Textänderung setzt Drupal
    // revision_translation_affected nicht, und latest() fände die Vorrevision.
    $latest->setRevisionTranslationAffected(TRUE);
    $latest->setRevisionTranslationAffectedEnforced(TRUE);
    $latest->save();
    return [
      'nid' => (int) $latest->id(),
      'vid' => (int) $latest->getRevisionId(),
      'moderation_state' => (string) $latest->get('moderation_state')->value,
      'bearbeitet' => $edited,
    ];
  }

  /**
   * Gibt es die Transition vom aktuellen Status aus (Workflow redaktion)?
   */
  protected function isValid(NodeInterface $latest, string $transition): bool {
    $workflow = $this->moderationInformation->getWorkflowForEntity($latest);
    if ($workflow === NULL || !isset(self::TRANSITIONS[$transition])) {
      return FALSE;
    }
    $plugin = $workflow->getTypePlugin();
    $from = (string) $latest->get('moderation_state')->value;
    $to = self::TRANSITIONS[$transition];
    return $plugin->hasState($from)
      && $plugin->hasTransitionFromStateToState($from, $to)
      && $plugin->getTransitionFromStateToState($from, $to)->id() === $transition;
  }

}
