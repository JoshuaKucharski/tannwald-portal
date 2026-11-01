<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Session\AnonymousUserSession;
use PHPUnit\Framework\Attributes\Group;

/**
 * Node-Grants am Node-Zugriff: Matrix Nutzer × Dokumente und Maschinen.
 */
#[Group('tannwald_access')]
final class NodeGrantsTest extends AccessKernelTestBase {

  /**
   * Spalten der Matrix (Schlüssel der Testwelt).
   */
  private const COLUMNS = ['d_ba', 'd_hf', 'd_anlage', 'd_mehrtyp', 'b_sb', 'd_kt', 'd_entwurf', 'm1', 'm2', 'm3'];

  /**
   * Erwartung je Nutzer, Reihenfolge wie COLUMNS (1 = sichtbar).
   *
   * Die Werte folgen der Vertragsregel, nicht dem Code: kunde-a besitzt M1
   * (TB-200), kunde-b M2 (KT-500), der Händler sieht B und C (TB-200, KT-500)
   * aus Land BR ohne Klasse 2; techniker-br zeigt, dass Exportregeln auch
   * für role:intern gelten.
   */
  private const MATRIX = [
    'kunde-a' => [1, 1, 1, 1, 1, 0, 0, 1, 0, 0],
    'kunde-b' => [0, 0, 0, 0, 0, 1, 0, 0, 1, 0],
    'haendler' => [1, 0, 0, 1, 1, 1, 0, 0, 1, 1],
    'techniker' => [1, 1, 1, 1, 1, 1, 0, 1, 1, 1],
    'techniker-br' => [1, 0, 1, 1, 1, 1, 0, 1, 1, 1],
    'redaktion' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
    'anonym' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
  ];

  /**
   * Testwelt.
   *
   * @var array<string, \Drupal\Core\Entity\EntityInterface>
   */
  private array $w;

  /**
   * Konten nach Matrix-Zeile.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  private array $accounts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->w = $this->createAccessWorld();
    $this->accounts = [
      'kunde-a' => $this->createRoleUser('kunde-a', 'kunde', self::idOf($this->w['firma_a']), 'CH'),
      'kunde-b' => $this->createRoleUser('kunde-b', 'kunde', self::idOf($this->w['firma_b']), 'BR'),
      'haendler' => $this->createRoleUser('haendler', 'haendler', self::idOf($this->w['firma_h']), 'BR'),
      'techniker' => $this->createRoleUser('techniker', 'techniker', NULL, 'CH'),
      'techniker-br' => $this->createRoleUser('techniker-br', 'techniker', NULL, 'BR'),
      'redaktion' => $this->createRoleUser('redaktion', 'redaktion', NULL, 'CH'),
      'anonym' => new AnonymousUserSession(),
    ];
    $this->rebuildGrants();
  }

  /**
   * Sichtbarkeit eines Matrix-Feldes.
   */
  private function sees(AccountInterface $account, string $column): int {
    return (int) $this->w[$column]->access('view', $account);
  }

  /**
   * Die Matrix.
   */
  public function testAccessMatrix(): void {
    $cells = 0;
    foreach (self::MATRIX as $row => $expected) {
      $actual = [];
      foreach (self::COLUMNS as $column) {
        $actual[] = $this->sees($this->accounts[$row], $column);
        $cells++;
      }
      $this->assertSame($expected, $actual, $row . ': ' . implode(', ', self::COLUMNS));
    }
    $this->assertSame(70, $cells);
  }

  /**
   * TC-03: Exportregel öffnet den Schaltplan, Firmensperre schliesst ihn.
   *
   * Der Händler hat TB-200 im Gebiet, aber kein Klasse-2-Land: erst eine
   * Exportregel für BR öffnet den Schaltplan, eine Sperre der Händlerfirma
   * schliesst ihn wieder.
   */
  public function testExportRuleOpensAndBlocks(): void {
    $dealer = $this->accounts['haendler'];
    $this->assertSame(1, $this->sees($dealer, 'd_ba'), 'Händler hat type:TB200.');
    $this->assertSame(0, $this->sees($dealer, 'd_hf'));

    $withBr = array_merge(self::KLASSE_2_COUNTRIES, ['BR']);
    $this->setExportRules($withBr, $withBr);
    $this->rebuildGrants();
    $this->assertSame(1, $this->sees($dealer, 'd_hf'));
    $this->assertSame(0, $this->sees($this->accounts['kunde-b'], 'd_hf'), 'kunde-b hat kein TB-200.');

    $this->setExportRules($withBr, $withBr, ['2001']);
    $this->rebuildGrants();
    $this->assertSame(0, $this->sees($dealer, 'd_hf'));
    $this->assertSame(1, $this->sees($dealer, 'd_ba'), 'Sperre gilt nur für Klasse 2.');
  }

  /**
   * Records in node_access: kombinierte Realms; Entwürfe tannwald_unpublished.
   */
  public function testAccessRecords(): void {
    $rows = $this->container->get('database')->select('node_access', 'na')
      ->fields('na', ['realm', 'gid', 'grant_view', 'grant_update', 'grant_delete'])
      ->condition('nid', $this->w['d_hf']->id())
      ->orderBy('realm')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    $grant = ['grant_view' => '1', 'grant_update' => '0', 'grant_delete' => '0'];
    $this->assertSame([
      ['realm' => 'tannwald_intern_klasse_2', 'gid' => '1'] + $grant,
      ['realm' => 'tannwald_type_klasse_2', 'gid' => self::idOf($this->w['tb200'])] + $grant,
    ], $rows);
    $draftRows = $this->container->get('database')->select('node_access', 'na')
      ->fields('na', ['realm', 'gid', 'grant_view', 'grant_update', 'grant_delete'])
      ->condition('nid', $this->w['d_entwurf']->id())
      ->execute()
      ->fetchAll(FetchAs::Associative);
    // 6.1 (Nachtrag 1): ein Sicht-Record für "view any unpublished content".
    $this->assertSame([['realm' => 'tannwald_unpublished', 'gid' => '1'] + $grant], $draftRows);
    $defaultRow = (int) $this->container->get('database')->select('node_access', 'na')
      ->condition('realm', 'all')
      ->countQuery()->execute()->fetchField();
    $this->assertSame(0, $defaultRow, 'Kein Standard-Grant "all".');
  }

  /**
   * Listen (Views, Entity-Query mit Access-Tag).
   *
   * Die Kundin kunde-a sieht fünf Dokumente und Bulletins und nur die eigene
   * Maschine.
   */
  public function testListQueries(): void {
    $this->setCurrentUser($this->accounts['kunde-a']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $documents = $storage->getQuery()->accessCheck(TRUE)->condition('type', ['dokument', 'bulletin'], 'IN')->sort('nid')->execute();
    $this->assertSame([
      self::idOf($this->w['d_ba']),
      self::idOf($this->w['d_hf']),
      self::idOf($this->w['d_anlage']),
      self::idOf($this->w['d_mehrtyp']),
      self::idOf($this->w['b_sb']),
    ], array_values(array_map('strval', $documents)));
    $machines = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'maschine')->execute();
    $this->assertSame([self::idOf($this->w['m1'])], array_values(array_map('strval', $machines)));
  }

  /**
   * Entwürfe: in Listen sichtbar für Redaktion, unsichtbar für Kundschaft.
   *
   * 6.1 (Nachtrag 1): Realm tannwald_unpublished für Konten mit "view any
   * unpublished content".
   */
  public function testUnpublishedVisibleToEditorsOnly(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $draft = self::idOf($this->w['d_entwurf']);
    $this->setCurrentUser($this->accounts['redaktion']);
    $this->assertTrue($this->accounts['redaktion']->hasPermission('view any unpublished content'));
    $editor = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'dokument')->execute();
    $this->assertContains($draft, array_map('strval', $editor), 'Redaktion sieht den Entwurf in der Liste.');
    $this->setCurrentUser($this->accounts['kunde-a']);
    $customer = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'dokument')->execute();
    $this->assertNotContains($draft, array_map('strval', $customer), 'Kundschaft sieht keinen Entwurf.');
    $this->assertFalse($this->w['d_entwurf']->access('view', $this->accounts['kunde-a']));
  }

}
