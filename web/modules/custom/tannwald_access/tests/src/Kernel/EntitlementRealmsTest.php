<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\EntitlementService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keys je Nutzer (6 Rollen × 3 Länder) und je Dokumentart.
 */
#[Group('tannwald_access')]
final class EntitlementRealmsTest extends AccessKernelTestBase {

  /**
   * Testwelt.
   *
   * @var array<string, \Drupal\Core\Entity\EntityInterface>
   */
  private array $w;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->w = $this->createAccessWorld();
  }

  /**
   * Der Dienst.
   */
  private function entitlement(): EntitlementService {
    return $this->container->get('tannwald_access.entitlement');
  }

  /**
   * Erwartete Keys: Basis × Klassen plus Zusatz-Keys, sortiert.
   *
   * @param string[] $bases
   *   Basis-Keys.
   * @param string[] $classes
   *   Exportklassen.
   * @param string[] $extra
   *   Keys ohne Export-Teil.
   *
   * @return string[]
   *   Sortierte Keys.
   */
  private static function expand(array $bases, array $classes, array $extra): array {
    $keys = $extra;
    foreach ($bases as $base) {
      foreach ($classes as $exportClass) {
        $keys[] = $base . '+export:' . $exportClass;
      }
    }
    sort($keys);
    return $keys;
  }

  /**
   * Sechs Rollen × drei Länder (CH, DE, Händlerland BR).
   */
  public function testAccountRealmsSixRolesThreeCountries(): void {
    $w = $this->w;
    $firmaA = 'firma:' . $w['firma_a']->id();
    $customerBases = ['serial:' . $w['m1']->id(), 'type:TB200'];
    $dealerBases = ['type:KT500', 'type:TB200'];
    $dealerExtra = ['firma:' . $w['firma_b']->id(), 'firma:' . $w['firma_c']->id()];
    $classes = [
      'CH' => ['keine', 'klasse_1', 'klasse_2'],
      'DE' => ['keine', 'klasse_1', 'klasse_2'],
      'BR' => ['keine', 'klasse_1'],
    ];
    $checked = 0;
    foreach (self::CONTRACT_ROLES as $role) {
      foreach ($classes as $country => $allowed) {
        $company = match ($role) {
          'kunde' => self::idOf($w['firma_a']),
          'haendler' => self::idOf($w['firma_h']),
          default => NULL,
        };
        $user = $this->createRoleUser("$role-$country", $role, $company, $country);
        $expected = match ($role) {
          'kunde' => self::expand($customerBases, $allowed, [$firmaA]),
          'haendler' => self::expand($dealerBases, $allowed, $dealerExtra),
          default => self::expand(['role:intern'], $allowed, ['role:intern']),
        };
        $this->assertSame($expected, $this->entitlement()->getRealmsForAccount($user), "$role-$country");
        $checked++;
      }
    }
    $this->assertSame(18, $checked);
    $this->assertSame([], $this->entitlement()->getRealmsForAccount(new AnonymousUserSession()));
  }

  /**
   * Keys der fünf Dokumentarten, der Maschine und der Zusammenfassung.
   */
  public function testNodeRealms(): void {
    $w = $this->w;
    $serial = 'serial:' . $w['m1']->id();
    $cases = [
      'd_ba' => ['role:intern+export:keine', 'type:TB200+export:keine'],
      'd_hf' => ['role:intern+export:klasse_2', 'type:TB200+export:klasse_2'],
      // Anlagenspezifisch: kein type:, obwohl field_maschinentyp gesetzt ist.
      'd_anlage' => ['role:intern+export:keine', $serial . '+export:keine'],
      'd_mehrtyp' => ['role:intern+export:klasse_1', 'type:TB200+export:klasse_1', 'type:TB400+export:klasse_1'],
      'b_sb' => ['role:intern+export:keine', $serial . '+export:keine', 'type:TB200+export:keine'],
      'm1' => ['firma:' . $w['firma_a']->id(), 'role:intern'],
    ];
    foreach ($cases as $key => $expected) {
      $node = $w[$key];
      $this->assertInstanceOf(NodeInterface::class, $node);
      $this->assertSame($expected, $this->entitlement()->getRealmsForNode($node), $key);
    }
    $summary = $this->createAccessNode('zusammenfassung', 'Zusammenfassung Schaltplan', ['field_dokument' => $w['d_hf']]);
    $this->assertSame($cases['d_hf'], $this->entitlement()->getRealmsForNode($summary));
    $orphan = $this->createAccessNode('zusammenfassung', 'Zusammenfassung ohne Dokument');
    $this->assertSame([], $this->entitlement()->getRealmsForNode($orphan));
  }

  /**
   * Abbildung Key → Realm und gid.
   */
  public function testGrantMapping(): void {
    $service = $this->entitlement();
    $tb200 = (int) $this->w['tb200']->id();
    $this->assertSame(['tannwald_type_klasse_2', $tb200], $service->grantForKey('type:TB200+export:klasse_2'));
    $this->assertSame(['tannwald_serial_keine', 41], $service->grantForKey('serial:41+export:keine'));
    $this->assertSame(['tannwald_intern_klasse_1', 1], $service->grantForKey('role:intern+export:klasse_1'));
    $this->assertSame(['tannwald_firma', 7], $service->grantForKey('firma:7'));
    $this->assertSame(['tannwald_intern', 1], $service->grantForKey('role:intern'));
    $this->assertNull($service->grantForKey('type:XX999+export:keine'));
    $this->assertNull($service->grantForKey('type:TB200'));
    $this->assertNull($service->grantForKey('role:extern+export:keine'));
  }

  /**
   * Cache: Exportregeln und Nutzerdaten invalidieren die Nutzer-Keys.
   */
  public function testCacheInvalidation(): void {
    $service = $this->entitlement();
    $customerBr = $this->createRoleUser('kunde-br', 'kunde', self::idOf($this->w['firma_a']), 'BR');
    $customerCh = $this->createRoleUser('kunde-ch', 'kunde', self::idOf($this->w['firma_a']), 'CH');
    $this->assertNotContains('type:TB200+export:klasse_2', $service->getRealmsForAccount($customerBr));
    $this->assertContains('type:TB200+export:klasse_2', $service->getRealmsForAccount($customerCh));

    // BR in Klasse 2, Firma A (1001) in Klasse 2 gesperrt.
    $withBr = array_merge(self::KLASSE_2_COUNTRIES, ['BR']);
    $this->setExportRules($withBr, $withBr, ['1001']);
    $this->assertNotContains('type:TB200+export:klasse_2', $service->getRealmsForAccount($customerCh));
    $this->assertContains('type:TB200+export:klasse_1', $service->getRealmsForAccount($customerCh));

    $this->setExportRules($withBr, $withBr);
    $this->assertContains('type:TB200+export:klasse_2', $service->getRealmsForAccount($customerBr));

    // Land ändern (Tag user:<uid>): ohne Land keine Exportklasse.
    $customerBr->set('field_land', NULL)->save();
    $this->assertSame([
      'firma:' . $this->w['firma_a']->id(),
      'serial:' . $this->w['m1']->id() . '+export:keine',
      'type:TB200+export:keine',
    ], $service->getRealmsForAccount($customerBr));

    // Neue Maschine (Tag node_list:maschine).
    $this->createAccessNode('maschine', 'TB400-2024-0001', [
      'field_maschinentyp' => $this->w['tb400'],
      'field_firma' => $this->w['firma_a'],
    ]);
    $this->assertContains('type:TB400+export:keine', $service->getRealmsForAccount($customerBr));
  }

}
