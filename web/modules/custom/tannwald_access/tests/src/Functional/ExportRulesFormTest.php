<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Functional;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\node\NodeAccessRebuild;
use Drupal\tannwald_access\ExportRuleService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Formular /compliance/exportregeln, Audit-Seite und CSV.
 */
#[Group('tannwald_access')]
final class ExportRulesFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'file', 'workflows', 'content_moderation', 'tannwald_access'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Nach der Installation steht keine Standardzeile nid 0 / Realm all mehr.
   *
   * Core schreibt sie in node_install(); ohne Neuaufbau gäbe sie jedem Konto
   * mit "access content" Sicht auf jeden veröffentlichten Node.
   */
  public function testInstallRemovesDefaultGrant(): void {
    $rows = (int) $this->container->get('database')->select('node_access', 'na')
      ->condition('nid', 0)
      ->condition('realm', 'all')
      ->countQuery()->execute()->fetchField();
    $this->assertSame(0, $rows);
  }

  /**
   * Speichern validiert, schreibt Konfiguration und Audit, stösst Rebuild an.
   */
  public function testSaveWritesAuditAndCsv(): void {
    $this->drupalGet('compliance/exportregeln');
    $this->assertSession()->statusCodeEquals(403);

    Role::create(['id' => 'compliance', 'label' => 'Compliance'])
      ->grantPermission('tannwald manage export rules')
      ->grantPermission('tannwald view audit log')
      ->save();
    Role::create(['id' => 'kunde', 'label' => 'Kunde'])->save();
    $customer = $this->createUser([], 'kunde-x', FALSE, ['roles' => ['kunde']]);
    $officer = $this->createUser([], 'compliance-x', FALSE, ['roles' => ['compliance']]);

    $this->drupalLogin($customer);
    $this->drupalGet('compliance/exportregeln');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('compliance/audit.csv');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($officer);
    $this->drupalGet('compliance/exportregeln');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Export rules');
    $this->assertSession()->fieldValueEquals('klasse_2_countries', 'CH, DE, AT, FR, IT, NL, US, JP');

    $this->submitForm(['klasse_1_countries' => 'CH, br'], 'Save configuration');
    $this->assertSession()->pageTextContains('br is not an ISO 3166-1 alpha-2 code in capital letters.');
    $this->assertSame(['CH', 'DE', 'AT', 'FR', 'IT', 'NL', 'US', 'JP'], $this->config('tannwald_access.settings')->get('export_rules.klasse_1.laender'));

    $this->container->get(NodeAccessRebuild::class)->setNeedsRebuild(FALSE);
    $this->submitForm([
      'klasse_1_countries' => 'CH, DE, BR',
      'klasse_2_countries' => 'CH, DE',
      'klasse_2_blocked' => '1001, 1001',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Access permissions are being recalculated.');
    // Der Testprozess cacht Konfiguration und State; die Seite schrieb im
    // Kindprozess.
    $this->container->get('config.factory')->reset(ExportRuleService::CONFIG);
    $this->container->get('state')->resetCache();
    $this->assertSame([
      'klasse_1' => ['laender' => ['CH', 'DE', 'BR'], 'gesperrte_firmen' => []],
      'klasse_2' => ['laender' => ['CH', 'DE'], 'gesperrte_firmen' => ['1001']],
    ], $this->container->get('config.factory')->get(ExportRuleService::CONFIG)->get('export_rules'));
    $this->assertTrue($this->container->get(NodeAccessRebuild::class)->needsRebuild());

    $rows = $this->container->get('database')->select('tannwald_audit', 'a')
      ->fields('a', ['uid', 'ereignis', 'entity_type', 'entity_id', 'details'])
      ->execute()->fetchAll(FetchAs::Associative);
    $this->assertCount(1, $rows);
    $this->assertSame((string) $officer->id(), $rows[0]['uid']);
    $this->assertSame('exportregel_geaendert', $rows[0]['ereignis']);
    $this->assertSame(['config', ExportRuleService::CONFIG], [$rows[0]['entity_type'], $rows[0]['entity_id']]);
    $details = json_decode($rows[0]['details'], TRUE);
    $this->assertSame(['CH', 'DE', 'AT', 'FR', 'IT', 'NL', 'US', 'JP'], $details['vorher']['klasse_2']['laender']);
    $this->assertSame(['CH', 'DE'], $details['nachher']['klasse_2']['laender']);

    $this->drupalGet('compliance/audit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('exportregel_geaendert');

    $this->drupalGet('compliance/audit.csv');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $csv = $this->getSession()->getPage()->getContent();
    $this->assertStringStartsWith("\xEF\xBB\xBFid;uid;ereignis;entity_type;entity_id;details;ip_land;created\n", $csv);
    $this->assertStringContainsString(';exportregel_geaendert;config;tannwald_access.settings;', $csv);
  }

}
