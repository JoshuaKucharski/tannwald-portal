<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\Tests\tannwald_ingest\Traits\SummaryFixturesTrait;

/**
 * Gemeinsamer Aufbau der 3.2-Kernel-Tests (MariaDB, KI gemockt).
 */
abstract class SummaryKernelTestBase extends KernelTestBase {

  use SummaryFixturesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'options', 'file', 'node', 'taxonomy', 'workflows',
    'content_moderation', 'datetime', 'language', 'key', 'ai', 'tannwald_access', 'tannwald_ingest',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if ($this->container->get('database')->driver() !== 'mysql') {
      $this->markTestSkipped('FULLTEXT needs MariaDB/MySQL (SIMPLETEST_DB).');
    }
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('tannwald_access', ['tannwald_audit']);
    $this->installSchema('tannwald_ingest', ['tannwald_chunk', 'tannwald_usage']);
    $this->installConfig(['filter', 'node', 'language', 'tannwald_access', 'tannwald_ingest']);
    \Drupal::moduleHandler()->loadInclude('tannwald_ingest', 'install');
    tannwald_ingest_ensure_fulltext($this->container->get('database'));
    $this->createSummaryModel();
    $this->mockAiProvider();
    // Konto uid 1 ist Superuser: belegen, damit Testkonten echte Rechte prüfen.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
  }

}
