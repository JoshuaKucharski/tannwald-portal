<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Kernel;

use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Form\FormState;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\Hook\AccessHooks;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drupal\workflows\Entity\Workflow;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vier-Augen-Prinzip für Sicherheits-Bulletins (FA-RED-01).
 */
#[Group('tannwald_access')]
final class NotOwnBulletinApprovalTest extends AccessKernelTestBase {

  /**
   * Quellstring der Meldung.
   */
  private const MESSAGE = 'Safety bulletins must not be approved by the person who created them (four-eyes principle).';

  /**
   * Erstellerin (redaktion).
   */
  private UserInterface $creator;

  /**
   * Zweite Redaktorin.
   */
  private UserInterface $reviewer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Workflow wie config/sync/workflows.workflow.redaktion.yml (A, 2.1).
    $workflow = Workflow::create(['id' => 'redaktion', 'label' => 'Redaktion', 'type' => 'content_moderation']);
    $plugin = $workflow->getTypePlugin();
    $this->assertInstanceOf(ContentModerationInterface::class, $plugin);
    $plugin->addState('zur_pruefung', 'Zur Prüfung');
    $plugin->addState('zurueckgewiesen', 'Zurückgewiesen');
    $plugin->deleteTransition('create_new_draft');
    $plugin->deleteTransition('publish');
    $allStates = ['draft', 'zur_pruefung', 'zurueckgewiesen', 'published'];
    $plugin->addTransition('entwurf_speichern', 'Entwurf speichern', $allStates, 'draft');
    $plugin->addTransition('freigeben', 'Freigeben', ['zur_pruefung'], 'published');
    $plugin->addTransition('zur_pruefung_senden', 'Zur Prüfung senden', ['draft', 'zurueckgewiesen'], 'zur_pruefung');
    $plugin->addTransition('zurueckweisen', 'Zurückweisen', ['zur_pruefung'], 'zurueckgewiesen');
    $plugin->addEntityTypeAndBundle('node', 'bulletin');
    $workflow->save();
    $role = Role::load('redaktion');
    foreach (['entwurf_speichern', 'freigeben', 'zur_pruefung_senden', 'zurueckweisen'] as $transition) {
      $role->grantPermission("use redaktion transition $transition");
    }
    $role->save();
    $this->creator = $this->createRoleUser('lea', 'redaktion', NULL, 'CH');
    $this->reviewer = $this->createRoleUser('marco', 'redaktion', NULL, 'CH');
  }

  /**
   * Legt als Erstellerin ein Bulletin an und sendet es zur Prüfung.
   */
  private function bulletinInReview(string $kind): NodeInterface {
    $this->setCurrentUser($this->creator);
    $node = $this->createAccessNode('bulletin', 'SB-2026-08', [
      'field_bulletin_art' => $kind,
      'moderation_state' => 'draft',
      'uid' => $this->creator->id(),
    ], FALSE);
    $node->set('moderation_state', 'zur_pruefung');
    $this->assertSame([], $this->messages($node));
    $node->save();
    return $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * Meldungen der Validierung.
   *
   * @return string[]
   *   Meldungen.
   */
  private function messages(NodeInterface $node): array {
    $messages = [];
    foreach ($node->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    return $messages;
  }

  /**
   * Erstellerin gibt eigenes Sicherheits-Bulletin frei: Verstoss.
   */
  public function testCreatorCannotApproveSafetyBulletin(): void {
    $node = $this->bulletinInReview('sicherheit');
    $node->set('moderation_state', 'published');
    $this->assertSame([self::MESSAGE], $this->messages($node));
  }

  /**
   * Andere Redaktorin gibt frei: ok, Audit "freigabe".
   */
  public function testOtherEditorCanApprove(): void {
    $node = $this->bulletinInReview('sicherheit');
    $this->setCurrentUser($this->reviewer);
    $node->set('moderation_state', 'published');
    $this->assertSame([], $this->messages($node));
    $node->save();
    $row = $this->container->get('database')->select('tannwald_audit', 'a')
      ->fields('a', ['uid', 'entity_id', 'details'])
      ->condition('ereignis', 'freigabe')
      ->execute()->fetchAssoc();
    $this->assertSame((string) $this->reviewer->id(), $row['uid']);
    $this->assertSame((string) $node->id(), $row['entity_id']);
    $this->assertSame(['von' => 'zur_pruefung', 'nach' => 'published', 'revision' => (int) $node->getRevisionId()], json_decode($row['details'], TRUE));
  }

  /**
   * Service-Bulletin der Erstellerin: ok.
   */
  public function testCreatorCanApproveServiceBulletin(): void {
    $node = $this->bulletinInReview('service');
    $node->set('moderation_state', 'published');
    $this->assertSame([], $this->messages($node));
  }

  /**
   * Moderationsformular (ohne Entity-Validierung): dieselbe Sperre.
   */
  public function testModerationFormValidation(): void {
    $node = $this->bulletinInReview('sicherheit');
    $form = [];
    $formState = (new FormState())->set('entity', $node)->setValue('new_state', 'published');
    AccessHooks::validateModerationForm($form, $formState);
    $this->assertSame([self::MESSAGE], array_map('strval', array_values($formState->getErrors())));

    $this->setCurrentUser($this->reviewer);
    $formState = (new FormState())->set('entity', $node)->setValue('new_state', 'published');
    AccessHooks::validateModerationForm($form, $formState);
    $this->assertSame([], $formState->getErrors());
  }

}
