<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Unit;

use Drupal\tannwald_access\ExportRuleService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exportregeln aus gemockter Konfiguration.
 */
#[Group('tannwald_access')]
final class ExportRuleServiceTest extends UnitTestCase {

  /**
   * Dienst mit gegebenen Regeln.
   *
   * @param array<string, mixed> $rules
   *   Wert von export_rules.
   */
  private function service(array $rules): ExportRuleService {
    return new ExportRuleService($this->getConfigFactoryStub([
      'tannwald_access.settings' => ['export_rules' => $rules],
    ]));
  }

  /**
   * Erlaubte Länder und gesperrte Firmen je Klasse.
   */
  public function testListsPerClass(): void {
    $service = $this->service([
      'klasse_1' => ['laender' => ['CH', 'DE', 'BR'], 'gesperrte_firmen' => []],
      'klasse_2' => ['laender' => ['CH', 'DE'], 'gesperrte_firmen' => ['1001', '']],
    ]);
    $this->assertSame(['CH', 'DE', 'BR'], $service->allowedCountries('klasse_1'));
    $this->assertSame(['CH', 'DE'], $service->allowedCountries('klasse_2'));
    $this->assertSame([], $service->blockedCompanies('klasse_1'));
    $this->assertSame(['1001'], $service->blockedCompanies('klasse_2'), 'Leerwerte fallen weg.');
  }

  /**
   * Unbekannte Klasse und "keine" liefern leere Listen.
   */
  public function testUnknownClassIsEmpty(): void {
    $service = $this->service([
      'klasse_2' => ['laender' => ['CH'], 'gesperrte_firmen' => ['1001']],
      'klasse_9' => ['laender' => ['CH'], 'gesperrte_firmen' => []],
    ]);
    $this->assertSame([], $service->allowedCountries('klasse_9'));
    $this->assertSame([], $service->blockedCompanies('klasse_9'));
    $this->assertSame([], $service->allowedCountries('keine'));
    $this->assertSame([], $service->allowedCountries(''));
    $this->assertSame([], $this->service([])->allowedCountries('klasse_2'), 'Fehlende Konfiguration.');
  }

  /**
   * Entscheid je Land und Firma.
   */
  public function testIsAllowed(): void {
    $service = $this->service([
      'klasse_1' => ['laender' => ['CH', 'BR'], 'gesperrte_firmen' => []],
      'klasse_2' => ['laender' => ['CH'], 'gesperrte_firmen' => ['1001']],
    ]);
    $this->assertTrue($service->isAllowed('keine', NULL, NULL), '"keine" gilt immer.');
    $this->assertTrue($service->isAllowed('klasse_1', 'BR', '2001'));
    $this->assertFalse($service->isAllowed('klasse_2', 'BR', '2001'), 'Land nicht freigegeben.');
    $this->assertTrue($service->isAllowed('klasse_2', 'CH', '1002'));
    $this->assertFalse($service->isAllowed('klasse_2', 'CH', '1001'), 'Firma gesperrt.');
    $this->assertTrue($service->isAllowed('klasse_2', 'CH', NULL), 'Ohne Firma keine Sperre.');
    $this->assertFalse($service->isAllowed('klasse_2', NULL, '1002'), 'Ohne Land keine Freigabe.');
    $this->assertFalse($service->isAllowed('klasse_9', 'CH', NULL), 'Unbekannte Klasse.');
  }

}
