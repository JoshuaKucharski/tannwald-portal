<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Functional;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\NodeAccessRebuild;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tannwald_access\Traits\AccessFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Leak-Test TC-02 (FA-DP-09) und TC-03 (FA-DP-05): Seite und Download.
 *
 * Erweiterung in 4.2 (E): Suche-Seite und /api/assistant als weitere
 * Testmethoden auf denselben Fixtures (createLeakFixtures()).
 */
#[Group('tannwald_access')]
class DocumentLeakTest extends BrowserTestBase {

  use AccessFixturesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'taxonomy',
    'file',
    'options',
    'workflows',
    'content_moderation',
    'tannwald_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Fixtures: Testwelt, Dateien, Nutzer.
   *
   * @var array<string, mixed>
   */
  protected array $fixtures;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContentModel();
    $this->fixtures = $this->createLeakFixtures();
  }

  /**
   * Baut Welt, private Dateien und die Konten kunde-a, kunde-b, haendler.
   *
   * @return array<string, mixed>
   *   Schlüssel der Testwelt (AccessFixturesTrait::createAccessWorld()) plus
   *   file_anlage, file_hf, kunde-a, kunde-b, haendler.
   */
  protected function createLeakFixtures(): array {
    $f = $this->createAccessWorld();
    $f['file_anlage'] = $this->attachFile($f['d_anlage'], 'abnahmeprotokoll-tb200-2021-0417.txt', "Abnahmeprotokoll TB200-2021-0417\nSiegeltemperatur 182 Grad");
    $f['file_hf'] = $this->attachFile($f['d_hf'], 'hf-schaltplan.txt', "HF sealing circuit diagram");
    $f['kunde-a'] = $this->createUser([], 'kunde-a', FALSE, [
      'roles' => ['kunde'],
      'field_firma' => $f['firma_a']->id(),
      'field_land' => 'CH',
    ]);
    $f['kunde-b'] = $this->createUser([], 'kunde-b', FALSE, [
      'roles' => ['kunde'],
      'field_firma' => $f['firma_b']->id(),
      'field_land' => 'BR',
    ]);
    $f['haendler'] = $this->createUser([], 'haendler', FALSE, [
      'roles' => ['haendler'],
      'field_firma' => $f['firma_h']->id(),
      'field_land' => 'BR',
    ]);
    $this->container->get(NodeAccessRebuild::class)->rebuild();
    return $f;
  }

  /**
   * Hängt eine private Datei unter private://dokumente/ an ein Dokument.
   */
  protected function attachFile(EntityInterface $node, string $filename, string $content): FileInterface {
    $this->assertInstanceOf(NodeInterface::class, $node);
    $directory = 'private://dokumente';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = $directory . '/' . $filename;
    file_put_contents($uri, $content);
    $file = File::create(['uri' => $uri, 'filename' => $filename, 'status' => 1]);
    $file->save();
    // Über das Feld, damit file_usage entsteht (wie im Seeder 2.3).
    $node->set('field_datei', ['target_id' => $file->id()]);
    $node->set('field_version', '1.0');
    $node->save();
    return $file;
  }

  /**
   * Audit-Zeilen eines Nutzers zu einem Ereignis.
   *
   * @return array<int, array<string, mixed>>
   *   Zeilen mit entity_type, entity_id, details (dekodiert).
   */
  protected function auditRows(string $ereignis, int $uid): array {
    $rows = $this->container->get('database')->select('tannwald_audit', 'a')
      ->fields('a', ['entity_type', 'entity_id', 'details'])
      ->condition('ereignis', $ereignis)
      ->condition('uid', $uid)
      ->orderBy('id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    foreach ($rows as &$row) {
      $row['details'] = json_decode((string) $row['details'], TRUE);
    }
    return $rows;
  }

  /**
   * Erwartet 403 mit der Zugriff-verweigert-Seite, ohne Dokumenttitel.
   */
  protected function assertDenied(string $path, string $title): void {
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    // Quellstring; die Oberfläche (de) zeigt „Zugriff verweigert“.
    $this->assertSession()->pageTextContains('Access denied');
    $this->assertSession()->pageTextNotContains($title);
  }

  /**
   * Pfad der rohen privaten Datei (/system/files/…).
   */
  protected function rawFilePath(FileInterface $file): string {
    return $this->container->get('file_url_generator')->generateAbsoluteString($file->getFileUri());
  }

  /**
   * TC-02: andere Kundenfirma gegen Anlagendokumentation von kunde-a.
   */
  public function testOtherCustomerIsDenied(): void {
    $f = $this->fixtures;
    $nid = (int) $f['d_anlage']->id();
    $title = $f['d_anlage']->label();
    $this->drupalLogin($f['kunde-b']);

    // Gegenkontrolle: kunde-b sieht das eigene KT-500-Dokument.
    $this->drupalGet('node/' . $f['d_kt']->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Wartungsplan KT-500');

    $this->assertDenied("node/$nid", $title);
    $this->assertDenied("dokument/$nid/download", $title);
    $this->drupalGet($this->rawFilePath($f['file_anlage']));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Siegeltemperatur');

    $rows = $this->auditRows('zugriff_verweigert', (int) $f['kunde-b']->id());
    $row = ['entity_type' => 'node', 'entity_id' => (string) $nid];
    $this->assertSame([
      $row + ['details' => ['route' => 'entity.node.canonical', 'nid' => $nid]],
      $row + ['details' => ['route' => 'tannwald_access.download', 'nid' => $nid]],
      $row + ['details' => ['route' => 'system.files', 'nid' => $nid, 'fid' => (int) $f['file_anlage']->id()]],
    ], $rows);
    $this->assertSame([], $this->auditRows('download', (int) $f['kunde-b']->id()));
  }

  /**
   * TC-03: Händler aus BR (ohne Klasse 2) gegen den HF-Schaltplan.
   */
  public function testDealerIsDeniedClassifiedDocument(): void {
    $f = $this->fixtures;
    $nid = (int) $f['d_hf']->id();
    $title = $f['d_hf']->label();
    $this->drupalLogin($f['haendler']);

    // Gegenkontrolle: TB-200 ohne Exportklasse ist sichtbar.
    $this->drupalGet('node/' . $f['d_ba']->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Betriebsanleitung TB-200');

    $this->assertDenied("node/$nid", $title);
    $this->assertDenied("dokument/$nid/download", $title);
    $routes = array_map(static fn(array $row): string => $row['details']['route'], $this->auditRows('zugriff_verweigert', (int) $f['haendler']->id()));
    $this->assertSame(['entity.node.canonical', 'tannwald_access.download'], $routes);
  }

  /**
   * Anonym: 403 auf Seite und Download, Audit mit uid 0.
   */
  public function testAnonymousIsDenied(): void {
    $f = $this->fixtures;
    $nid = (int) $f['d_anlage']->id();
    $this->assertDenied("node/$nid", (string) $f['d_anlage']->label());
    $this->assertDenied("dokument/$nid/download", (string) $f['d_anlage']->label());
    $routes = array_map(static fn(array $row): string => $row['details']['route'], $this->auditRows('zugriff_verweigert', 0));
    $this->assertSame(['entity.node.canonical', 'tannwald_access.download'], $routes);
  }

  /**
   * Besitzerin: Seite 200, Download liefert die Datei, Audit "download".
   */
  public function testOwnerCanViewAndDownload(): void {
    $f = $this->fixtures;
    $nid = (int) $f['d_anlage']->id();
    $this->drupalLogin($f['kunde-a']);
    $this->drupalGet("node/$nid");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Anlagendokumentation TB200-2021-0417');

    $this->drupalGet("dokument/$nid/download");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'attachment');
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'abnahmeprotokoll-tb200-2021-0417.txt');
    $this->assertSame("Abnahmeprotokoll TB200-2021-0417\nSiegeltemperatur 182 Grad", $this->getSession()->getPage()->getContent());

    $expected = [
      'entity_type' => 'node',
      'entity_id' => (string) $nid,
      'details' => ['fid' => (int) $f['file_anlage']->id(), 'version' => '1.0'],
    ];
    $this->assertSame([$expected], $this->auditRows('download', (int) $f['kunde-a']->id()));
    $this->assertSame([], $this->auditRows('zugriff_verweigert', (int) $f['kunde-a']->id()));
  }

}
