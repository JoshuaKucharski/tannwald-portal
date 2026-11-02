<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Unit;

use Drupal\tannwald_ingest\Store\VectorPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Embeddings als float32 Little Endian hin und zurück.
 */
#[Group('tannwald_ingest')]
class VectorPackTest extends TestCase {

  /**
   * Hin und zurück: 1024 Zufallswerte, Abweichung < 1e-6.
   */
  public function testRoundTrip(): void {
    mt_srand(4711);
    $vector = [];
    for ($i = 0; $i < 1024; $i++) {
      $vector[] = mt_rand(-1000000, 1000000) / 1000000;
    }
    $blob = VectorPack::pack($vector);
    $this->assertSame(4096, strlen($blob));
    $back = VectorPack::unpack($blob);
    $this->assertSame(range(0, 1023), array_keys($back), 'Keys are 0-based.');
    $max = 0.0;
    foreach ($vector as $i => $value) {
      $max = max($max, abs($value - $back[$i]));
    }
    $this->assertLessThan(1e-6, $max);
  }

  /**
   * Byte-Reihenfolge: 1.0 als float32 LE = 00 00 80 3F.
   */
  public function testLittleEndian(): void {
    $vector = array_fill(0, 1024, 0.0);
    $vector[0] = 1.0;
    $this->assertSame('0000803f', bin2hex(substr(VectorPack::pack($vector), 0, 4)));
  }

  /**
   * Falsche Länge wird abgelehnt (Vektor und Blob).
   */
  public function testWrongLength(): void {
    $this->expectException(\InvalidArgumentException::class);
    VectorPack::pack([0.1, 0.2]);
  }

  /**
   * Ein abgeschnittener Blob wird abgelehnt.
   */
  public function testTruncatedBlob(): void {
    $this->expectException(\InvalidArgumentException::class);
    VectorPack::unpack(str_repeat("\0", 4095));
  }

}
