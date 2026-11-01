<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Unit;

use Drupal\tannwald_ingest\Chunker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Chunker: fünf Dokumentformen, Tabellen atomar, Überlappung gemessen.
 */
#[Group('tannwald_ingest')]
class ChunkerTest extends TestCase {

  /**
   * Titel der Testdokumente.
   */
  protected const TITLE = 'Betriebsanleitung TB-200';

  /**
   * Erzeugt $count unterschiedliche Sätze (je ca. 90 Zeichen).
   */
  protected static function sentences(int $count, string $topic): string {
    $out = [];
    for ($i = 1; $i <= $count; $i++) {
      $out[] = "Satz $i zur $topic beschreibt Prüfschritt $i an der Siegelstation mit Temperatur und Druck.";
    }
    return implode(' ', $out);
  }

  /**
   * Prüft die Grundregeln jedes Ergebnisses.
   */
  protected function assertWellFormed(array $chunks): void {
    foreach ($chunks as $i => $chunk) {
      $this->assertSame(['chunk_index', 'heading_path', 'text', 'token_count'], array_keys($chunk));
      $this->assertSame($i, $chunk['chunk_index']);
      $this->assertSame((int) ceil(mb_strlen($chunk['text']) / 4), $chunk['token_count']);
      $this->assertNotSame('', trim($chunk['text']));
      $this->assertDoesNotMatchRegularExpression('/<[a-z]/i', $chunk['text'], 'Tags removed.');
      $this->assertDoesNotMatchRegularExpression('/[ \t]{2,}/', $chunk['text'], 'Whitespace squeezed.');
    }
  }

  /**
   * Form 1: nur h2, kurze Abschnitte → ein Chunk je h2, leere übersprungen.
   */
  public function testOnlyH2(): void {
    $html = '<h2 id="kapitel-1">1 Aufbau</h2><p>Die TB-200   formt,
      siegelt und stanzt.</p><h2>2 Leer</h2><h2>3 Sicherheit</h2><p>Schutzhaube schliessen.</p>';
    $chunks = (new Chunker())->chunk($html, self::TITLE);
    $this->assertWellFormed($chunks);
    $this->assertSame(['1 Aufbau', '3 Sicherheit'], array_column($chunks, 'heading_path'));
    $this->assertSame("1 Aufbau\nDie TB-200 formt, siegelt und stanzt.", $chunks[0]['text'], 'Heading stays at the start of the text.');
  }

  /**
   * Form 2: h2 + h3 mit langen Absätzen → mehrere Chunks, Überlappung ≤ 60.
   */
  public function testLongSectionsSplitWithOverlap(): void {
    $html = '<h2>7 Reinigung und Wartung</h2><p>' . self::sentences(4, 'Wartung') . '</p>'
      . '<h3>7.3 Reinigung Siegelstation</h3><p>' . self::sentences(30, 'Reinigung') . '</p><p>' . self::sentences(12, 'Kontrolle') . '</p>';
    $chunks = (new Chunker())->chunk($html, self::TITLE);
    $this->assertWellFormed($chunks);
    $this->assertSame('7 Reinigung und Wartung', $chunks[0]['heading_path']);
    $sub = array_values(array_filter($chunks, static fn(array $c): bool => $c['heading_path'] === '7 Reinigung und Wartung › 7.3 Reinigung Siegelstation'));
    $this->assertGreaterThanOrEqual(3, count($sub), 'About 3800 characters need at least three chunks.');
    foreach ($sub as $chunk) {
      $this->assertLessThanOrEqual(Chunker::TARGET_TOKENS, $chunk['token_count']);
      $this->assertStringStartsWith("7.3 Reinigung Siegelstation\n", $chunk['text']);
    }
    // Überlappung: der Anfang von Chunk n+1 (nach der Überschrift) wiederholt
    // ganze Sätze vom Ende von Chunk n.
    $prefix = "7.3 Reinigung Siegelstation\n";
    for ($i = 1; $i < count($sub); $i++) {
      $previous = substr($sub[$i - 1]['text'], strlen($prefix));
      $current = substr($sub[$i]['text'], strlen($prefix));
      $overlap = '';
      for ($len = min(strlen($previous), strlen($current)); $len > 0; $len--) {
        if (str_ends_with($previous, substr($current, 0, $len))) {
          $overlap = substr($current, 0, $len);
          break;
        }
      }
      $tokens = Chunker::tokens($overlap);
      $this->assertGreaterThan(0, $tokens, "Chunk $i overlaps its predecessor.");
      $this->assertLessThanOrEqual(Chunker::OVERLAP_TOKENS, $tokens);
      $this->assertMatchesRegularExpression('/^Satz \d+ .*\.$/', $overlap, 'Overlap consists of whole sentences.');
    }
    // Keine Information geht verloren: jeder Satz steht in einem Chunk.
    $all = implode(' ', array_column($chunks, 'text'));
    foreach ([1, 15, 30] as $n) {
      $this->assertStringContainsString("Satz $n zur Reinigung", $all);
    }
    $this->assertStringContainsString('Satz 12 zur Kontrolle', $all);
  }

  /**
   * Form 3: Tabelle > 350 Tokens bleibt ein Chunk, Kopf zuerst, Warnung.
   */
  public function testLargeTableIsAtomic(): void {
    $rows = '';
    for ($i = 1; $i <= 40; $i++) {
      $rows .= "<tr><td>E-47$i</td><td>Störung Nummer $i an der Station</td><td>Teil 47$i prüfen und tauschen</td></tr>";
    }
    $html = '<h2>9 Störungen</h2><p>Einleitung zur Tabelle.</p><h3>9.1 Störungstabelle</h3>'
      . '<table><tbody>' . $rows . '</tbody><thead><tr><th>Code</th><th>Störung</th><th>Abhilfe</th></tr></thead></table>'
      . '<p>Nach der Tabelle folgt ein Hinweis.</p>';
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $chunks = (new Chunker($logger))->chunk($html, self::TITLE);
    $this->assertWellFormed($chunks);
    $tableChunks = array_values(array_filter($chunks, static fn(array $c): bool => str_contains($c['text'], 'E-471 |')));
    $this->assertCount(1, $tableChunks, 'Table is never split.');
    $table = $tableChunks[0];
    $this->assertGreaterThan(Chunker::TARGET_TOKENS, $table['token_count']);
    $this->assertStringContainsString('E-4740 | Störung Nummer 40 an der Station | Teil 4740 prüfen und tauschen', $table['text']);
    $this->assertStringStartsWith("9.1 Störungstabelle\nCode | Störung | Abhilfe\nE-471 |", $table['text'], 'Header row first.');
    $this->assertSame('9 Störungen › 9.1 Störungstabelle', $table['heading_path']);
    $this->assertStringNotContainsString('Nach der Tabelle', $table['text']);
    $this->assertStringContainsString('Nach der Tabelle folgt ein Hinweis.', end($chunks)['text']);
  }

  /**
   * Form 4: Listen zeilenweise (ul mit „- “, ol nummeriert).
   */
  public function testLists(): void {
    $html = '<h2>5 Bedienung</h2><ul><li>Schutzhaube <strong>schliessen</strong></li><li>Folie einlegen</li></ul>'
      . '<ol><li>Start drücken</li><li>Zyklus prüfen</li></ol>';
    $chunks = (new Chunker())->chunk($html, self::TITLE);
    $this->assertWellFormed($chunks);
    $this->assertCount(1, $chunks);
    $this->assertSame("5 Bedienung\n- Schutzhaube schliessen\n- Folie einlegen\n1. Start drücken\n2. Zyklus prüfen", $chunks[0]['text']);
  }

  /**
   * Form 5: Text vor dem ersten h2 trägt den Titel; h3 ohne h2; NFC.
   */
  public function testTextBeforeFirstH2(): void {
    $html = "<p>Gu\u{0308}ltig fu\u{0308}r alle Maschinen.</p><h3>0.1 Hinweis</h3><p>Lesen.</p><h2>1 Aufbau</h2><p>Text.</p>";
    $chunks = (new Chunker())->chunk($html, self::TITLE);
    $this->assertWellFormed($chunks);
    $this->assertSame([self::TITLE, self::TITLE . ' › 0.1 Hinweis', '1 Aufbau'], array_column($chunks, 'heading_path'));
    $this->assertSame('Gültig für alle Maschinen.', $chunks[0]['text'], 'NFC, no heading line before the first h2.');
    $this->assertSame([], (new Chunker())->chunk('', self::TITLE));
  }

}
