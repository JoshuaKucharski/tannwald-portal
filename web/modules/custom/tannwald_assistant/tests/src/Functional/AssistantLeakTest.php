<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Functional;

use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Leak-Erweiterung (2.4 / FA-SE-04 / FA-AI-02) für Suche und Assistent.
 *
 * Eigene Klasse statt Methoden in C's DocumentLeakTest: dessen Modulliste
 * lädt tannwald_assistant nicht. Die Testwelt kommt aus C's
 * AccessFixturesTrait (dieselben Konten kunde-a, kunde-b, haendler).
 * Der Anlagendoku-Chunk trägt zusätzlich kunde-b's Keys (simulierte Drift
 * zwischen access_keys und Grants): nur $node->access('view') im Retriever
 * hält ihn zurück — die zweite Schranke wird so beweisbar.
 */
#[Group('tannwald_assistant')]
final class AssistantLeakTest extends AssistantFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $machineDoc = $this->w['d_anlage'];
    $hf = $this->w['d_hf'];
    $this->assertInstanceOf(NodeInterface::class, $machineDoc);
    $this->assertInstanceOf(NodeInterface::class, $hf);
    $driftKeys = $this->container->get('tannwald_access.entitlement')->getRealmsForAccount($this->users['kunde-b']);
    $this->index($machineDoc, [
      [
        '1 Abnahme › 1.1 Abnahmeprotokoll',
        "1.1 Abnahmeprotokoll\nAbnahmeprotokoll TB200-2021-0417, Siegeltemperatur 182 Grad.",
        4,
      ],
    ], $driftKeys);
    $this->index($hf, [
      [
        '1 HF › 1.1 Schaltplan',
        "1.1 Schaltplan\nHF sealing circuit diagram Schaltplan Hochfrequenz Abnahmeprotokoll.",
        5,
      ],
    ]);
    $this->indexManual();
  }

  /**
   * Suche-Seite: kunde-b ohne Anlagendoku, haendler ohne Schaltplan HF.
   */
  public function testSearchPageLeak(): void {
    $machineDoc = (string) $this->w['d_anlage']->id();
    $hf = (string) $this->w['d_hf']->id();

    $this->loginAs('kunde-b');
    foreach (['volltext', 'hybrid'] as $modus) {
      $this->drupalGet('suche', ['query' => ['q' => 'Abnahmeprotokoll', 'modus' => $modus]]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementNotExists('css', 'li.tannwald-search__hit[data-nid="' . $machineDoc . '"]');
      $this->assertSession()->pageTextNotContains('Siegeltemperatur 182');
      $this->assertSession()->elementNotExists('css', 'fieldset[data-facet="maschinentyp"]');
    }
    [, $live] = $this->api('GET', 'api/search/live', NULL, FALSE, ['q' => 'Abnahmeprotokoll']);
    $this->assertNotContains((int) $machineDoc, array_column($live['treffer'], 'nid'));
    $this->drupalLogout();

    $this->loginAs('haendler');
    $this->drupalGet('suche', ['query' => ['q' => 'Schaltplan Hochfrequenz', 'modus' => 'volltext']]);
    $this->assertSession()->elementNotExists('css', 'li.tannwald-search__hit[data-nid="' . $hf . '"]');
    $this->assertSession()->pageTextNotContains('HF sealing circuit');
    $this->drupalLogout();

    // Gegenkontrolle: der Eigentümer sieht seine Anlagendoku.
    $this->loginAs('kunde-a');
    $this->drupalGet('suche', ['query' => ['q' => 'Abnahmeprotokoll', 'modus' => 'volltext']]);
    $this->assertSession()->elementExists('css', 'li.tannwald-search__hit[data-nid="' . $machineDoc . '"]');
  }

  /**
   * Assistent: kunde-b bekommt „abgelehnt“ ohne Quelle, nie die Anlagendoku.
   */
  public function testAssistantLeak(): void {
    $machineDoc = (int) $this->w['d_anlage']->id();
    $this->loginAs('kunde-b');
    [$status, $json] = $this->api('POST', 'api/assistant', [
      'frage' => 'Welche Siegeltemperatur steht im Abnahmeprotokoll?',
      'session' => 'leak',
    ]);
    $this->assertSame(200, $status);
    $this->assertSame('abgelehnt', $json['status']);
    $this->assertSame([], $json['quellen']);
    $this->assertStringNotContainsString('182', $json['antwort_html']);
    $this->assertSame(0, (int) $this->container->get('state')->get('tannwald_assistant_test.chat_calls', 0), 'Ohne sichtbare Treffer kein Chat-Aufruf.');
    $this->drupalLogout();

    $this->loginAs('haendler');
    $question = ['frage' => 'Was zeigt der Schaltplan Hochfrequenz?', 'session' => 'leak'];
    [, $json] = $this->api('POST', 'api/assistant', $question);
    $this->assertNotContains((int) $this->w['d_hf']->id(), array_column($json['quellen'], 'nid'));
    $this->assertNotContains($machineDoc, array_column($json['quellen'], 'nid'));
  }

}
