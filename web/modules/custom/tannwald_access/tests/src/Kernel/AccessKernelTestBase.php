<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_access\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeAccessRebuild;
use Drupal\Tests\tannwald_access\Traits\AccessFixturesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Gemeinsamer Aufbau der Kernel-Tests von tannwald_access.
 */
abstract class AccessKernelTestBase extends KernelTestBase {

  use AccessFixturesTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'file',
    'node',
    'taxonomy',
    'workflows',
    'content_moderation',
    'tannwald_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('tannwald_access', ['tannwald_audit']);
    $this->installConfig(['filter', 'node', 'tannwald_access']);
    // Die uid 1 umgeht jede Zugriffsprüfung; ein Platzhalter belegt sie.
    $this->createUser([], 'placeholder-uid-1');
    $this->createContentModel();
  }

  /**
   * Legt einen Nutzer mit Rolle, Firma und Land an.
   */
  protected function createRoleUser(string $name, string $role, ?string $companyId, string $country): UserInterface {
    $values = ['roles' => [$role], 'field_land' => $country];
    if ($companyId !== NULL) {
      $values['field_firma'] = $companyId;
    }
    return $this->createUser([], $name, FALSE, $values);
  }

  /**
   * Baut node_access neu und leert den Access-Cache.
   */
  protected function rebuildGrants(): void {
    $this->container->get(NodeAccessRebuild::class)->rebuild();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
  }

}
