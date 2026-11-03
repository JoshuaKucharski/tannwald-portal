<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_ingest\Kernel;

use Drupal\tannwald_assistant\Provider\ProviderResolver;
use Drupal\tannwald_ingest\Summary\SummaryGenerator;
use PHPUnit\Framework\Attributes\Group;

/**
 * F13: Die Usage-Zeile einer Zusammenfassung trägt die Kosten des Providers.
 *
 * Der Generator wird mit einem echten ProviderResolver gebaut (die Klasse ist
 * final). Dessen Einstellungen kommen als Settings-Override
 * ($GLOBALS['config']), nicht als gespeicherte Konfiguration:
 * tannwald_assistant ist in diesen Kernel-Tests nicht installiert, sein Schema
 * fehlt, und gespeichert wird nichts.
 */
#[Group('tannwald_ingest')]
class SummaryCostTest extends SummaryKernelTestBase {

  /**
   * Preis > 0 (DashScope) ergibt Kosten > 0, Ollama (Preis 0) ergibt 0.
   */
  public function testUsageCarriesProviderCost(): void {
    $cases = [
      // 1'590 Tokens (Doppel: 1'500 + 90) × CHF 0.0008 / 1'000 = 0.0013.
      'dashscope' => ['qwen-plus', 0.0008, 0.0013],
      'ollama' => ['qwen2.5:7b', 0.0, 0.0],
    ];
    $database = $this->container->get('database');
    $configFactory = $this->container->get('config.factory');
    try {
      foreach ($cases as $providerId => [$model, $price, $expected]) {
        $GLOBALS['config']['tannwald_assistant.settings'] = [
          'provider_chat' => $providerId,
          'provider_embeddings' => 'ollama',
          'providers' => [
            $providerId => [
              'chat_model' => $model,
              'embedding_model' => 'bge-m3',
              'embedding_dimensions' => 1024,
              'kosten_pro_1k_chf' => $price,
            ],
          ],
        ];
        $configFactory->reset('tannwald_assistant.settings');
        $generator = new SummaryGenerator(
          $this->container->get('entity_type.manager'),
          $this->container->get('tannwald_ingest.chunker'),
          $this->container->get('ai.provider'),
          $configFactory,
          $this->container->get('tannwald_ingest.usage'),
          $this->container->get('datetime.time'),
          $this->container->get('keyvalue'),
          $this->container->get('lock'),
          $database,
          new ProviderResolver($configFactory, $this->container->get('ai.provider')),
        );
        $dokument = $this->createPublishedDocument('Betriebsanleitung ' . $providerId);
        $this->assertNotNull($generator->generate($dokument, 'de'));

        $row = $database->query("SELECT modell, kosten_chf FROM {tannwald_usage} WHERE kontext = 'ingest' ORDER BY id DESC")->fetchObject();
        if (!is_object($row)) {
          $this->fail('No usage row for the summary.');
        }
        $this->assertSame($model, $row->modell);
        $this->assertEqualsWithDelta($expected, (float) $row->kosten_chf, 0.00001, sprintf('Cost of the %s summary.', $providerId));
      }
    }
    finally {
      unset($GLOBALS['config']['tannwald_assistant.settings']);
    }
  }

}
