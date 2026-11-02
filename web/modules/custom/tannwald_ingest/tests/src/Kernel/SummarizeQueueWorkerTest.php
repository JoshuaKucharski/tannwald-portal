<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Kernel;

use Drupal\tannwald_ingest\Plugin\QueueWorker\SummarizeQueueWorker;
use PHPUnit\Framework\Attributes\Group;

/**
 * Queue tannwald_summarize: Job → Zusammenfassung, Fehler → 3 Versuche.
 */
#[Group('tannwald_ingest')]
class SummarizeQueueWorkerTest extends SummaryKernelTestBase {

  /**
   * Zählt Zusammenfassungen.
   */
  protected function summaryCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', 'zusammenfassung')->count()->execute();
  }

  /**
   * Stellt einen Job für die aktuelle Revision ein.
   */
  protected function queueJob(int $nid, int $vid, string $langcode = 'de'): void {
    $this->container->get('queue')->get(SummarizeQueueWorker::QUEUE)->createItem([
      'nid' => $nid,
      'vid' => $vid,
      'langcode' => $langcode,
    ]);
  }

  /**
   * Job erzeugt die Zusammenfassung; zweiter Job derselben Revision nichts.
   */
  public function testJobCreatesSummary(): void {
    $dokument = $this->createPublishedDocument();
    $this->queueJob((int) $dokument->id(), (int) $dokument->getRevisionId());
    $this->queueJob((int) $dokument->id(), (int) $dokument->getRevisionId());
    $this->assertSame(['ok', 'skipped'], $this->runQueue(SummarizeQueueWorker::QUEUE));
    $this->assertSame(1, $this->summaryCount());
    $this->assertCount(1, $this->chatCalls);
  }

  /**
   * Fehler-Provider: drei Versuche insgesamt, dann verworfen.
   */
  public function testRetries(): void {
    $this->chatFailing = TRUE;
    $dokument = $this->createPublishedDocument();
    $this->queueJob((int) $dokument->id(), (int) $dokument->getRevisionId());
    $this->assertSame(['retry', 'retry', 'failed'], $this->runQueue(SummarizeQueueWorker::QUEUE));
    $this->assertCount(3, $this->chatCalls);
    $this->assertSame(0, $this->summaryCount());
    $this->assertSame(0, $this->container->get('queue')->get(SummarizeQueueWorker::QUEUE)->numberOfItems());
  }

  /**
   * Abgeschaltet, unveröffentlicht, fremdes Bundle: übersprungen, kein Chat.
   */
  public function testSkips(): void {
    $dokument = $this->createPublishedDocument();
    $draft = $this->createPublishedDocument('Entwurf', 'de', ['moderation_state' => 'draft']);
    $this->queueJob((int) $draft->id(), (int) $draft->getRevisionId());
    $this->queueJob((int) $dokument->id(), (int) $dokument->getRevisionId(), 'fr');
    $this->queueJob((int) $dokument->id(), 999999);
    $this->assertSame(['skipped', 'skipped', 'skipped'], $this->runQueue(SummarizeQueueWorker::QUEUE));

    $this->config('tannwald_ingest.settings')->set('summaries_enabled', FALSE)->save();
    $this->queueJob((int) $dokument->id(), (int) $dokument->getRevisionId());
    $this->assertSame(['skipped'], $this->runQueue(SummarizeQueueWorker::QUEUE));
    $this->assertSame([], $this->chatCalls);
    $this->assertSame(0, $this->summaryCount());
  }

}
