<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Functional;

use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Suche-Seite: TC-06, Facetten nur Sichtbares, aktuell, Maschine, Pager.
 */
#[Group('tannwald_assistant')]
final class SearchPageTest extends AssistantFunctionalTestBase {

  /**
   * Treffer-nids und matched_by der aktuellen Seite.
   *
   * @return array<int, array{nid: string, matched_by: string, heading: string}>
   *   Treffer in Reihenfolge.
   */
  private function hits(): array {
    $hits = [];
    foreach ($this->getSession()->getPage()->findAll('css', 'li.tannwald-search__hit') as $li) {
      $hits[] = [
        'nid' => (string) $li->getAttribute('data-nid'),
        'matched_by' => (string) $li->getAttribute('data-matched-by'),
        'heading' => trim((string) $li->find('css', 'h3')?->getText()),
      ];
    }
    return $hits;
  }

  /**
   * Facettenwerte (Label → Zahl) einer Facettengruppe.
   *
   * @return array<string, int>
   *   Label => count.
   */
  private function facet(string $name): array {
    $values = [];
    foreach ($this->getSession()->getPage()->findAll('css', 'fieldset[data-facet="' . $name . '"] label') as $label) {
      $count = (int) $label->find('css', '.count')?->getText();
      $text = trim(str_replace((string) $count, '', $label->getText()));
      $values[$text] = $count;
    }
    return $values;
  }

  /**
   * TC-06: „Folie reisst beim Siegeln“ nur hybrid; „E-4711“ in beiden Modi.
   */
  public function testFulltextVersusHybrid(): void {
    $this->indexManual();
    $this->loginAs('kunde-a');

    $this->drupalGet('suche', ['query' => ['q' => 'Folie reisst beim Siegeln', 'modus' => 'volltext']]);
    $this->assertSession()->statusCodeEquals(200);
    $headings = array_column($this->hits(), 'heading');
    $this->assertNotContains('9 Störungen › 9.4 Störungen an der Siegelstation', $headings, 'Volltext kennt kein Stemming.');

    $this->drupalGet('suche', ['query' => ['q' => 'Folie reisst beim Siegeln', 'modus' => 'hybrid']]);
    $hits = $this->hits();
    $this->assertSame('9 Störungen › 9.4 Störungen an der Siegelstation', $hits[0]['heading'], 'Hybrid: 9.4 zuerst (Vektor-Zweig).');
    $this->assertSame('vector', $hits[0]['matched_by']);
    $this->assertSession()->pageTextContains('Hit by meaning');
    $this->assertSession()->elementAttributeContains('css', '#tannwald-search-q', 'data-live-url', '/api/search/live');

    foreach (['volltext', 'hybrid'] as $modus) {
      $this->drupalGet('suche', ['query' => ['q' => 'E-4711', 'modus' => $modus]]);
      $this->assertSame('9 Störungen › 9.1 Störungstabelle', $this->hits()[0]['heading'], $modus);
      $this->assertSession()->responseContains('<mark>4711</mark>');
    }
  }

  /**
   * Facetten nur über Sichtbares (FA-SE-04); Filter, aktuell, Maschine, Pager.
   */
  public function testFacetsCurrentVersionsMachineAndPager(): void {
    $this->indexManual();
    $kt = $this->w['d_kt'];
    $this->assertInstanceOf(NodeInterface::class, $kt);
    $this->index($kt, [
      ['2 Wartung › 2.1 Siegelstation', "2.1 Siegelstation\nWartungsplan KT-500: Siegelstation monatlich prüfen.", 6],
    ]);
    $mehrtyp = $this->w['d_mehrtyp'];
    $this->assertInstanceOf(NodeInterface::class, $mehrtyp);
    // Chunk nur mit TB-400 (für den Maschinenfilter).
    $tb400 = (int) $this->w['tb400']->id();
    $this->index($mehrtyp, [
      ['3 TB-400 › 3.1 Siegelstation', "3.1 Siegelstation\nSchulung Siegelstation TB-400.", 7, [$tb400]],
    ]);

    // kunde-b (KT-500) sieht keine TB-200-Dokumente, auch nicht als Facette.
    $this->loginAs('kunde-b');
    $this->drupalGet('suche', ['query' => ['q' => 'Siegelstation', 'modus' => 'volltext']]);
    $this->assertSame([(string) $kt->id()], array_values(array_unique(array_column($this->hits(), 'nid'))));
    $this->assertSame(['KT-500' => 1], $this->facet('maschinentyp'));
    $this->drupalLogout();

    $this->loginAs('kunde-a');
    $base = ['q' => 'Siegelstation', 'modus' => 'volltext'];
    $this->drupalGet('suche', ['query' => $base]);
    $nids = array_values(array_unique(array_column($this->hits(), 'nid')));
    $this->assertContains((string) $this->w['d_ba']->id(), $nids);
    $this->assertNotContains((string) $kt->id(), $nids);
    $this->assertSame(['TB-200' => 1, 'TB-400' => 1], $this->facet('maschinentyp'));
    $this->assertSame(['Betriebsanleitung' => 2], $this->facet('dokumenttyp'));

    // Facettenfilter TB-400.
    $this->drupalGet('suche', ['query' => $base + ['maschinentyp' => [$tb400]]]);
    $this->assertSame([(string) $mehrtyp->id()], array_values(array_unique(array_column($this->hits(), 'nid'))));

    // maschine_nid = M1 (TB-200): der TB-400-Chunk fällt weg.
    $this->drupalGet('suche', ['query' => $base + ['maschine_nid' => $this->w['m1']->id()]]);
    $this->assertNotContains((string) $mehrtyp->id(), array_column($this->hits(), 'nid'));
    $this->assertContains((string) $this->w['d_ba']->id(), array_column($this->hits(), 'nid'));

    // „Nur aktuelle Versionen“: neue Standard-Revision, alte Chunks bleiben.
    $manual = $this->w['d_ba'];
    $this->assertInstanceOf(NodeInterface::class, $manual);
    $oldVid = (int) $manual->getRevisionId();
    $manual->setNewRevision(TRUE);
    $manual->save();
    $this->assertNotSame($oldVid, (int) $manual->getRevisionId());
    $this->drupalGet('suche', ['query' => ['q' => 'Siegelstation', 'modus' => 'volltext']]);
    $this->assertContains((string) $manual->id(), array_column($this->hits(), 'nid'), 'Ohne Filter: alte Revision sichtbar.');
    $this->drupalGet('suche', ['query' => ['q' => 'Siegelstation', 'modus' => 'volltext', 'aktuell' => 1]]);
    $this->assertNotContains((string) $manual->id(), array_column($this->hits(), 'nid'), 'aktuell=1: alte Revision weg.');

    // Pager: 12 Kapitel-Treffer → 10 + 2.
    $chunks = [];
    for ($i = 1; $i <= 12; $i++) {
      $chunks[] = ['4 Pager › 4.' . $i . ' Abschnitt', "4.$i Abschnitt\nBlätterwort Nummer $i.", 10 + $i];
    }
    $this->index($manual, $chunks);
    $this->drupalGet('suche', ['query' => ['q' => 'Blätterwort', 'modus' => 'volltext']]);
    $this->assertCount(10, $this->hits());
    $this->assertSession()->pageTextContains('12 hits');
    $this->drupalGet('suche', ['query' => ['q' => 'Blätterwort', 'modus' => 'volltext', 'page' => 1]]);
    $this->assertCount(2, $this->hits());
  }

}
