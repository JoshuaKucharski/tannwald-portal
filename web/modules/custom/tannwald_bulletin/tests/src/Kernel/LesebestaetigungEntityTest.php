<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_bulletin\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\NodeInterface;
use Drupal\tannwald_bulletin\ConfirmationService;
use Drupal\tannwald_bulletin\Entity\Lesebestaetigung;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lesebestätigung: eindeutig, unveränderbar, Zugriff, Audit.
 */
#[Group('tannwald_bulletin')]
final class LesebestaetigungEntityTest extends BulletinKernelTestBase {

  /**
   * Dienst.
   */
  private function service(): ConfirmationService {
    return $this->container->get('tannwald_bulletin.confirmations');
  }

  /**
   * Knoten der Welt.
   */
  private function node(string $key): NodeInterface {
    $node = $this->w[$key];
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Zahl der Zeilen in lesebestaetigung.
   */
  private function rowCount(): int {
    return (int) $this->container->get('database')->select('lesebestaetigung')->countQuery()->execute()->fetchField();
  }

  /**
   * Idempotent, Entity-Constraint und DB-Index.
   */
  public function testUnique(): void {
    // Die Referenz-Validierung prüft mit dem aktuellen Nutzer.
    $this->setCurrentUser($this->user('kunde-a'));
    $first = $this->service()->confirm($this->node('b_sb'), $this->node('m1'), $this->user('kunde-a'));
    $second = $this->service()->confirm($this->node('b_sb'), $this->node('m1'), $this->user('kunde-a'));
    $this->assertSame($first->id(), $second->id());
    $this->assertSame(1, $this->rowCount());

    $duplicate = Lesebestaetigung::create([
      'uid' => $this->user('kunde-a')->id(),
      'bulletin_nid' => $this->node('b_sb')->id(),
      'maschine_nid' => $this->node('m1')->id(),
      'bestaetigt_am' => 1000,
    ]);
    $messages = array_map(static fn($violation): string => (string) $violation->getMessage(), iterator_to_array($duplicate->validate()));
    $this->assertSame(['This bulletin has already been confirmed for this machine.'], $messages);

    $this->expectException(EntityStorageException::class);
    $duplicate->save();
  }

  /**
   * Update und Delete verweigert, auch uid 1; presave-Schranke.
   */
  public function testImmutable(): void {
    $confirmation = $this->service()->confirm($this->node('b_sb'), $this->node('m1'), $this->user('kunde-a'));
    $superUser = $this->container->get('entity_type.manager')->getStorage('user')->load(1);
    foreach (['kunde-a', 'redaktion', 'compliance'] as $name) {
      $this->assertFalse($confirmation->access('update', $this->user($name)), "update $name");
      $this->assertFalse($confirmation->access('delete', $this->user($name)), "delete $name");
    }
    $this->assertFalse($confirmation->access('update', $superUser), 'update uid 1');
    $this->assertFalse($confirmation->access('delete', $superUser), 'delete uid 1');
    $this->assertFalse($this->container->get('entity_type.manager')->getAccessControlHandler('lesebestaetigung')->createAccess(NULL, $superUser));

    $this->assertTrue($confirmation->access('view', $this->user('kunde-a')), 'eigene');
    $this->assertFalse($confirmation->access('view', $this->user('kunde-c')), 'fremde Kundin');
    $this->assertFalse($confirmation->access('view', $this->user('techniker')), 'techniker');
    $this->assertTrue($confirmation->access('view', $this->user('redaktion')), 'redaktion');
    $this->assertTrue($confirmation->access('view', $this->user('compliance')), 'tannwald view audit log');

    $loaded = Lesebestaetigung::load($confirmation->id());
    $loaded->set('bestaetigt_am', 1);
    try {
      $loaded->save();
      $this->fail('Presave must throw.');
    }
    catch (EntityStorageException $exception) {
      // Die Storage verpackt die LogicException aus dem presave-Hook.
      $this->assertInstanceOf(\LogicException::class, $exception->getPrevious());
      $this->assertStringContainsString('immutable', $exception->getMessage());
    }
    $this->assertSame($confirmation->getConfirmedTime(), Lesebestaetigung::load($confirmation->id())->getConfirmedTime());
  }

  /**
   * Audit, Felder, Abfragen und Verweigerungen.
   */
  public function testConfirmAuditAndQueries(): void {
    $this->setCurrentUser($this->user('kunde-a'));
    $confirmation = $this->service()->confirm($this->node('b_sb'), $this->node('m1'), $this->user('kunde-a'));
    $this->assertSame('CH', $confirmation->getCountry());
    $this->assertSame((int) $this->node('m1')->id(), $confirmation->getMachineId());

    $rows = $this->container->get('database')->select('tannwald_audit', 'a')
      ->fields('a', ['uid', 'entity_type', 'entity_id', 'details'])
      ->condition('ereignis', 'lesebestaetigung')
      ->execute()->fetchAll();
    $this->assertCount(1, $rows);
    $this->assertSame(['lesebestaetigung', (string) $confirmation->id()], [$rows[0]->entity_type, $rows[0]->entity_id]);
    $this->assertSame([
      'bulletin_nid' => (int) $this->node('b_sb')->id(),
      'maschine_nid' => (int) $this->node('m1')->id(),
    ], json_decode($rows[0]->details, TRUE));
    // Zweite Bestätigung: kein zweiter Audit-Eintrag.
    $this->service()->confirm($this->node('b_sb'), $this->node('m1'), $this->user('kunde-a'));
    $this->assertSame(1, (int) $this->container->get('database')->select('tannwald_audit')->condition('ereignis', 'lesebestaetigung')->countQuery()->execute()->fetchField());

    $at = $this->service()->confirmedAt($this->node('b_sb'), $this->user('kunde-a'));
    $this->assertSame([
      'bestaetigt_am' => $confirmation->getConfirmedTime(),
      'maschine_nid' => (int) $this->node('m1')->id(),
      'uid' => (int) $this->user('kunde-a')->id(),
    ], $at);
    $this->assertNull($this->service()->confirmedAt($this->node('b_sb'), $this->user('kunde-c')));
    $this->assertSame($confirmation->getConfirmedTime(), $this->service()->isConfirmed((int) $this->node('b_sb')->id(), (int) $this->node('m1')->id(), (int) $this->user('kunde-a')->id()));

    // Offen: b_sb (TB-200, Seriennummer M1) betrifft kunde-a (bestätigt),
    // kunde-c (M3) und den Händler (M3 im Gebiet).
    $open = $this->service()->openConfirmations($this->node('b_sb'));
    $this->assertSame([(int) $this->user('kunde-c')->id(), (int) $this->user('haendler')->id()], array_keys($open));

    $denied = 0;
    foreach ([['kunde-b', 'm1'], ['kunde-a', 'm2'], ['kunde-a', 'm3']] as [$name, $machine]) {
      try {
        $this->service()->confirm($this->node('b_sb'), $this->node($machine), $this->user($name));
      }
      catch (AccessDeniedHttpException) {
        $denied++;
      }
    }
    $this->assertSame(3, $denied, 'Fremde Maschine oder Bulletin ohne Sicht.');
    $this->assertSame(1, $this->rowCount());
  }

}
