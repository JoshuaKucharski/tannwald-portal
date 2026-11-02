<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wächter für die Export-Disziplin und Entscheid D6a (Aufgabe 1.1).
 *
 * Liest die exportierte Konfiguration in config/sync, ohne Drupal zu booten:
 * Site-Name und Standardsprache, die Sprachen en und fr, die in 1.1
 * aktivierten Module, die Demo-Hilfen nur im Split „dev“ und — D6a — dass
 * search_api, search_api_db und facets nicht installiert sind (der
 * Keyword-Zweig ist MariaDB-FULLTEXT hinter ChunkStoreInterface).
 */
#[Group('tannwald')]
final class ConfigSyncGuardTest extends UnitTestCase {

  /**
   * Module, die Aufgabe 1.1 aktiviert (Auftrag tannwald-1, Schritt 2).
   */
  private const REQUIRED_MODULES = [
    'language',
    'locale',
    'content_translation',
    'config_translation',
    'workflows',
    'content_moderation',
    'ai',
    'ai_provider_ollama',
    'symfony_mailer',
    'config_split',
    'migrate',
    'migrate_plus',
    'migrate_tools',
  ];

  /**
   * Module, die nach D6a nie installiert sein dürfen.
   */
  private const FORBIDDEN_MODULES = ['search_api', 'search_api_db', 'facets'];

  /**
   * Demo-Hilfen, die nur über den Split „dev“ kommen.
   */
  private const DEV_SPLIT_MODULES = ['masquerade', 'queue_ui'];

  /**
   * Site-Name und Standardsprache stehen so in system.site.
   */
  public function testSiteNameAndDefaultLanguage(): void {
    $site = $this->readSync('system.site');
    $this->assertSame('myTannwald', $site['name'] ?? NULL, 'system.site: name');
    $this->assertSame('de', $site['default_langcode'] ?? NULL, 'system.site: default_langcode');
  }

  /**
   * Neben Deutsch sind Englisch und Französisch angelegt.
   */
  public function testAdditionalLanguagesExported(): void {
    foreach (['de', 'en', 'fr'] as $langcode) {
      $this->assertSame($langcode, $this->readSync('language.entity.' . $langcode)['id'] ?? NULL, "Sprache $langcode exportiert");
    }
  }

  /**
   * Die Module aus 1.1 sind aktiviert, die verbotenen nicht.
   */
  public function testModuleListInCoreExtension(): void {
    $modules = $this->readSync('core.extension')['module'] ?? [];
    foreach (self::REQUIRED_MODULES as $module) {
      $this->assertArrayHasKey($module, $modules, "Modul $module fehlt in core.extension.yml");
    }
    foreach (self::FORBIDDEN_MODULES as $module) {
      $this->assertArrayNotHasKey($module, $modules, "Modul $module ist installiert — Entscheid D6a verbietet es");
    }
  }

  /**
   * Masquerade und Queue UI stehen nur im Split „dev“, nie in core.extension.
   */
  public function testDevHelpersOnlyInDevSplit(): void {
    $modules = $this->readSync('core.extension')['module'] ?? [];
    $split = $this->readSync('config_split.config_split.dev');
    foreach (self::DEV_SPLIT_MODULES as $module) {
      $this->assertArrayNotHasKey($module, $modules, "Modul $module gehört in den Split dev, nicht in core.extension.yml (Export ohne aktiven Split?)");
      $this->assertArrayHasKey($module, $split['module'] ?? [], "Modul $module fehlt im Split dev");
    }
    $this->assertSame('../config/split/dev', $split['folder'] ?? NULL, 'Split dev: Ordner');
  }

  /**
   * Liest eine Datei aus config/sync als Array.
   */
  private function readSync(string $name): array {
    $file = dirname(__DIR__, 3) . '/config/sync/' . $name . '.yml';
    $this->assertFileExists($file);
    $data = Yaml::decode((string) file_get_contents($file));
    $this->assertIsArray($data, "$name.yml ist kein YAML-Mapping");
    return $data;
  }

}
