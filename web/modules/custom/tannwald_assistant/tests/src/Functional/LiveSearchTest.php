<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Functional;

use PHPUnit\Framework\Attributes\Group;

/**
 * GET /api/search/live: Top 5 je Dokument, kurze Eingabe leer, anonym 403.
 */
#[Group('tannwald_assistant')]
final class LiveSearchTest extends AssistantFunctionalTestBase {

  /**
   * Ein Treffer je Dokument, höchstens fünf; Felder nach Vertrag.
   */
  public function testTopFivePerDocument(): void {
    [$status] = $this->api('GET', 'api/search/live', NULL, FALSE, ['q' => 'Livewort']);
    $this->assertSame(403, $status, 'Anonym.');

    for ($i = 1; $i <= 7; $i++) {
      $node = $this->createAccessNode('dokument', 'Dokument ' . $i, [
        'field_maschinentyp' => [$this->w['tb200']],
        'field_exportklasse' => 'keine',
      ]);
      $this->index($node, [
        ['1 Teil › 1.1 Erster Abschnitt', "1.1 Erster Abschnitt\nLivewort eins.", 20 + $i],
        ['1 Teil › 1.2 Zweiter Abschnitt', "1.2 Zweiter Abschnitt\nLivewort zwei.", 40 + $i],
      ]);
    }
    $this->loginAs('kunde-a');
    [$status, $json] = $this->api('GET', 'api/search/live', NULL, FALSE, ['q' => 'Livewort', 'modus' => 'volltext']);
    $this->assertSame(200, $status);
    $this->assertSame(['treffer', 'dauer_ms'], array_keys($json));
    $this->assertCount(5, $json['treffer']);
    $nids = array_column($json['treffer'], 'nid');
    $this->assertSame($nids, array_values(array_unique($nids)), 'Ein Treffer je Dokument.');
    $this->assertSame(['nid', 'titel', 'heading_path', 'langcode', 'dokumenttyp', 'url'], array_keys($json['treffer'][0]));
    $this->assertSame('Betriebsanleitung', $json['treffer'][0]['dokumenttyp']);

    [, $short] = $this->api('GET', 'api/search/live', NULL, FALSE, ['q' => 'L']);
    $this->assertSame(['treffer' => [], 'dauer_ms' => 0], $short);
  }

}
