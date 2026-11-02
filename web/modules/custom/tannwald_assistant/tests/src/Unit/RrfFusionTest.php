<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tannwald_assistant\Rrf;
use PHPUnit\Framework\Attributes\Group;

/**
 * RRF-Fusion (k = 60): Summe, matched_by, Reihenfolge, stabile Ties.
 */
#[Group('tannwald_assistant')]
final class RrfFusionTest extends UnitTestCase {

  /**
   * Treffer-Zeile wie vom ChunkStore.
   */
  private static function row(int $id, int $nid, int $chunkIndex = 0): array {
    return [
      'id' => $id,
      'nid' => $nid,
      'chunk_index' => $chunkIndex,
      'heading_path' => 'K' . $id,
      'score' => 99.0,
    ];
  }

  /**
   * Ein Treffer in beiden Listen gewinnt und heisst "both".
   */
  public function testHitInBothListsIsBothAndFirst(): void {
    $fused = Rrf::fuse([
      'keyword' => [self::row(10, 1), self::row(11, 1, 1), self::row(12, 2)],
      'vector' => [self::row(12, 2), self::row(13, 3)],
    ]);
    // 11 (keyword Rang 2) und 13 (vector Rang 2) haben beide 1/62: nid 1 vor 3.
    $this->assertSame([12, 10, 11, 13], array_column($fused, 'id'));
    $this->assertSame('both', $fused[0]['matched_by']);
    $this->assertEqualsWithDelta(1 / 63 + 1 / 61, $fused[0]['score'], 1e-12);
    $this->assertSame(['keyword' => 3, 'vector' => 1], $fused[0]['ranks']);
    $this->assertSame('keyword', $fused[1]['matched_by']);
    $this->assertEqualsWithDelta(1 / 61, $fused[1]['score'], 1e-12);
    $this->assertSame('keyword', $fused[2]['matched_by']);
    $this->assertEqualsWithDelta(1 / 62, $fused[2]['score'], 1e-12);
    $this->assertSame('vector', $fused[3]['matched_by']);
    $this->assertEqualsWithDelta(1 / 62, $fused[3]['score'], 1e-12);
    // Der Store-Score wird durch die RRF-Summe ersetzt.
    $this->assertNotSame(99.0, $fused[0]['score']);
  }

  /**
   * Gleicher Score: stabil nach nid, dann chunk_index, dann id.
   */
  public function testTiesAreStableByNidAndChunkIndex(): void {
    $fused = Rrf::fuse([
      'keyword' => [self::row(30, 9, 2), self::row(31, 4, 0)],
      'vector' => [self::row(32, 4, 5), self::row(33, 9, 1)],
    ]);
    // Rang 1 je Liste: 30 (nid 9) und 32 (nid 4) → 32 zuerst.
    $this->assertSame([32, 30, 31, 33], array_column($fused, 'id'));
    $again = Rrf::fuse([
      'vector' => [self::row(32, 4, 5), self::row(33, 9, 1)],
      'keyword' => [self::row(30, 9, 2), self::row(31, 4, 0)],
    ]);
    $this->assertSame(array_column($fused, 'id'), array_column($again, 'id'), 'Reihenfolge der Listen ändert das Ergebnis nicht.');
    $sameNid = Rrf::fuse(['keyword' => [self::row(41, 5, 3)], 'vector' => [self::row(40, 5, 1)]]);
    $this->assertSame([40, 41], array_column($sameNid, 'id'));
  }

  /**
   * Skala für min_score: Rang 6 einer Liste liegt über 0.015, Rang 7 darunter.
   */
  public function testScoreScaleForMinScore(): void {
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
      $rows[] = self::row($i, $i);
    }
    $fused = Rrf::fuse(['keyword' => $rows]);
    $this->assertGreaterThanOrEqual(0.015, $fused[5]['score']);
    $this->assertLessThan(0.015, $fused[6]['score']);
    $this->assertEqualsWithDelta(1 / 66, $fused[5]['score'], 1e-12);
  }

  /**
   * Leere Listen, Doppelte in einer Liste, eigenes k.
   */
  public function testEdgeCases(): void {
    $this->assertSame([], Rrf::fuse([]));
    $this->assertSame([], Rrf::fuse(['keyword' => [], 'vector' => []]));
    $fused = Rrf::fuse(['keyword' => [self::row(1, 1), self::row(1, 1), self::row(2, 2)]]);
    $this->assertCount(2, $fused);
    $this->assertSame(['keyword' => 2], $fused[1]['ranks'], 'Doppelte Zeile belegt keinen Rang.');
    $custom = Rrf::fuse(['keyword' => [self::row(1, 1)]], 10);
    $this->assertEqualsWithDelta(1 / 11, $custom[0]['score'], 1e-12);
    $this->expectException(\InvalidArgumentException::class);
    Rrf::fuse(['keyword' => []], 0);
  }

}
