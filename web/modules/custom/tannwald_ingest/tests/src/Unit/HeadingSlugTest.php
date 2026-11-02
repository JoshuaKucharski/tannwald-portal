<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Unit;

use Drupal\tannwald_ingest\HeadingSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HeadingSlug nach Vertrag (RunBook §1.1), mit und ohne intl gleich.
 */
#[Group('tannwald_ingest')]
class HeadingSlugTest extends TestCase {

  /**
   * Überschrift => erwarteter Slug.
   */
  public static function headings(): array {
    return [
      'contract example' => ['9.4 Störungen an der Siegelstation', '9-4-stoerungen-an-der-siegelstation'],
      'uppercase umlauts' => ['ÄÖÜ-Prüfung', 'aeoeue-pruefung'],
      'sharp s' => ['Maßnahmen bei Störungen', 'massnahmen-bei-stoerungen'],
      'capital sharp s' => ['GROẞE Teile', 'grosse-teile'],
      'french accents' => [
        '7.3 Nettoyage de la station de scellage — sécurité',
        '7-3-nettoyage-de-la-station-de-scellage-securite',
      ],
      'french ligature and cedilla' => ['Œuvre, façade et précautions', 'oeuvre-facade-et-precautions'],
      'english' => ['2.3 Lockout/Tagout (LOTO)!', '2-3-lockout-tagout-loto'],
      'decomposed input' => ["9.4 Sto\u{0308}rungen", '9-4-stoerungen'],
      'special chars squeezed' => ['  --Teil  4711 / E-4711 :: Lieferzeit?  ', 'teil-4711-e-4711-lieferzeit'],
      'non latin removed' => ['Kapitel 5 – 设置 ✓', 'kapitel-5'],
      'nbsp and tabs' => ["1.2\u{00A0}Technische\tDaten", '1-2-technische-daten'],
      'empty' => ['', ''],
    ];
  }

  /**
   * Slug je Überschrift.
   */
  #[DataProvider('headings')]
  public function testSlug(string $heading, string $expected): void {
    $this->assertSame($expected, HeadingSlug::of($heading));
  }

  /**
   * Beide Wege (Normalizer und feste Tabelle) liefern dasselbe.
   */
  #[DataProvider('headings')]
  public function testBothPathsAgree(string $heading, string $expected): void {
    $this->assertTrue(class_exists(\Normalizer::class), 'intl is available in the container.');
    $this->assertSame($expected, HeadingSlug::slug($heading, TRUE));
    $this->assertSame($expected, HeadingSlug::slug($heading, FALSE));
  }

}
