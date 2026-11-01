<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Kernel;

use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Plugin\QueueWorker\EmbedQueueWorker;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Review-Transitionen: freigeben, zurueckweisen, entwurf_speichern.
 */
#[Group('tannwald_ingest')]
class ReviewTransitionTest extends SummaryKernelTestBase {

  /**
   * Konto mit Rolle.
   */
  protected function account(string $name, string $role): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => [$role]]);
    $user->save();
    return $user;
  }

  /**
   * Zusammenfassung zur Prüfung für ein neues Dokument.
   */
  protected function summary(string $title = 'Betriebsanleitung TB-200'): NodeInterface {
    $summary = $this->container->get('tannwald_ingest.summary_generator')->generate($this->createPublishedDocument($title), 'de');
    $this->assertInstanceOf(NodeInterface::class, $summary);
    // Jobs aus dem Anlegen (Dokument, Zusammenfassung) abarbeiten.
    $this->runQueue(EmbedQueueWorker::QUEUE);
    return $summary;
  }

  /**
   * Audit-Zeilen „freigabe“ eines Nodes.
   */
  protected function approvals(int $nid): int {
    return (int) $this->container->get('database')->query("SELECT COUNT(*) FROM {tannwald_audit} WHERE ereignis = 'freigabe' AND entity_type = 'node' AND entity_id = :id", [':id' => $nid])->fetchField();
  }

  /**
   * Chunk-Zeilen eines Nodes.
   */
  protected function chunks(int $nid): int {
    return (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {tannwald_chunk} WHERE nid = :nid', [':nid' => $nid])->fetchField();
  }

  /**
   * Freigeben: published, ein index-Job, genau eine Audit-Zeile, Chunks.
   */
  public function testApprove(): void {
    $summary = $this->summary();
    $nid = (int) $summary->id();
    $this->assertSame(0, $this->chunks($nid), 'No chunks while under review.');
    $compliance = $this->account('pia', 'compliance');

    $result = $this->container->get('tannwald_ingest.review_transition')->apply($summary, 'freigeben', NULL, $compliance);
    $this->assertSame('published', $result['moderation_state']);
    $this->assertFalse($result['bearbeitet']);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($nid);
    $this->assertTrue($node->isPublished());
    $this->assertSame((int) $node->getRevisionId(), $result['vid']);
    $this->assertSame('freigeben durch pia', $node->getRevisionLogMessage());
    $this->assertSame((int) $compliance->id(), (int) $node->getRevisionUserId());

    $jobs = array_values(array_filter($this->queuedJobs(EmbedQueueWorker::QUEUE), static fn(array $j): bool => $j['nid'] === $nid && $j['action'] === 'index'));
    $this->assertSame([['nid' => $nid, 'vid' => $result['vid'], 'langcode' => 'de', 'action' => 'index']], $jobs, 'Exactly one index job from the node hook.');
    $this->assertSame(1, $this->approvals($nid), 'Exactly one audit row from tannwald_access.');

    $this->runQueue(EmbedQueueWorker::QUEUE);
    $this->assertGreaterThan(0, $this->chunks($nid));
    $row = $this->container->get('database')->query('SELECT access_keys FROM {tannwald_chunk} WHERE nid = :nid', [':nid' => $nid])->fetchField();
    $this->assertStringContainsString('type:TB200+export:keine', $row, 'Summary inherits the document keys (C).');
  }

  /**
   * Zurückweisen: zurueckgewiesen, Chunks des Nodes gelöscht.
   */
  public function testReject(): void {
    $summary = $this->summary();
    $nid = (int) $summary->id();
    // Reste wie von einer früheren Indexierung.
    $this->container->get('tannwald_ingest.chunk_store')->replaceForRevision($nid, (int) $summary->getRevisionId(), 'de', [[
      'chunk_index' => 0, 'heading_path' => 'x', 'title' => 'x', 'text' => 'Rest', 'token_count' => 1,
      'embedding' => self::hashVector('Rest'), 'access_keys' => ['type:TB200+export:keine'],
      'exportklasse' => 'keine', 'dokumenttyp_tid' => 0, 'maschinentyp_tids' => [],
    ],
    ]);
    $this->assertSame(1, $this->chunks($nid));

    $result = $this->container->get('tannwald_ingest.review_transition')->apply($summary, 'zurueckweisen', NULL, $this->account('lea', 'redaktion'));
    $this->assertSame('zurueckgewiesen', $result['moderation_state']);
    $this->assertContains(['nid' => $nid, 'action' => 'delete'], $this->queuedJobs(EmbedQueueWorker::QUEUE));
    $this->runQueue(EmbedQueueWorker::QUEUE);
    $this->assertSame(0, $this->chunks($nid));
    $this->assertSame(0, $this->approvals($nid));
  }

  /**
   * Entwurf speichern: draft mit bearbeitetem, gefiltertem Text.
   */
  public function testSaveDraftWithEditedText(): void {
    $summary = $this->summary();
    $result = $this->container->get('tannwald_ingest.review_transition')->apply($summary, 'entwurf_speichern', '<p>Neu <em>bearbeitet</em>.</p><script>x</script><h2>weg</h2>', $this->account('lea', 'redaktion'));
    $this->assertSame('draft', $result['moderation_state']);
    $this->assertTrue($result['bearbeitet']);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($result['vid']);
    $this->assertSame('<p>Neu <em>bearbeitet</em>.</p>xweg', $node->get('body')->value);
    $this->assertSame('dokument_html', $node->get('body')->format);
    $this->assertFalse($node->isPublished());
  }

  /**
   * Rechte und ungültige Übergänge.
   */
  public function testRejectsForbiddenAndInvalid(): void {
    $summary = $this->summary();
    $transition = $this->container->get('tannwald_ingest.review_transition');
    try {
      $transition->apply($summary, 'freigeben', NULL, $this->account('anna', 'kunde'));
      $this->fail('kunde must not approve.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
    try {
      $transition->apply($summary, 'entwurf_speichern', NULL, $this->account('pia', 'compliance'));
      $this->fail('compliance has no entwurf_speichern.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
    $lea = $this->account('lea', 'redaktion');
    $this->assertSame(['freigeben', 'zurueckweisen', 'entwurf_speichern'], $transition->allowed($summary, $lea));
    $transition->apply($summary, 'entwurf_speichern', NULL, $lea);
    $this->assertSame(['entwurf_speichern'], $transition->allowed($summary, $lea), 'From draft only entwurf_speichern of the three.');
    $this->expectException(BadRequestHttpException::class);
    $transition->apply($summary, 'freigeben', NULL, $lea);
  }

}
