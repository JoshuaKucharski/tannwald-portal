<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_bulletin\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\Gettext;
use Drupal\tannwald_bulletin\Mailer\BulletinMailer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Mailtexte in der Empfängersprache aus translations/*.po (6.1).
 *
 * Seit die Konstante FRENCH entfernt ist, muss fr.po jeden Quelltext
 * übersetzen, sonst ginge eine englische Mail an französische Kundschaft.
 */
#[Group('tannwald_bulletin')]
final class BulletinMailerTextTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'language', 'locale'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location', 'locale_file']);
    $this->installConfig(['language', 'locale']);
    $directory = dirname(__DIR__, 3) . '/translations';
    foreach (['de', 'fr'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
      Gettext::fileToDatabase((object) ['uri' => "$directory/$langcode.po", 'langcode' => $langcode], []);
    }
    $this->container->get('string_translation')->reset();
  }

  /**
   * Jeder Quelltext ist in de und fr übersetzt; Platzhalter bleiben.
   */
  public function testEverySourceTextIsTranslated(): void {
    $mailer = (new \ReflectionClass(BulletinMailer::class))->newInstanceWithoutConstructor();
    foreach (['de', 'fr'] as $langcode) {
      foreach (BulletinMailer::SOURCE as $key => $source) {
        $this->assertNotSame($source, $mailer->text($key, $langcode), "$langcode: $key übersetzt");
      }
    }
    $this->assertSame('Lire le bulletin', $mailer->text('read', 'fr'));
    $this->assertSame('Bonjour Lucie,', $mailer->text('greeting', 'fr', ['@name' => 'Lucie']));
    $subject = $mailer->text('subject', 'fr', ['@id' => 'SB-2026-08', '@title' => 'Test']);
    $this->assertStringStartsWith('Bulletin de sécurité SB-2026-08 : Test', $subject);
    $this->assertSame('Read bulletin', $mailer->text('read', 'en'));
  }

}
