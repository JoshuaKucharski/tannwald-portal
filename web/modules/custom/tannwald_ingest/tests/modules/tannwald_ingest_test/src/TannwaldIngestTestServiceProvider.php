<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Ersetzt ai.provider nur in den Diensten von tannwald_ingest.
 */
final class TannwaldIngestTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $fake = new Reference('tannwald_ingest_test.ai_provider');
    $container->getDefinition('tannwald_ingest.embedding')->replaceArgument(0, $fake);
    $container->getDefinition('tannwald_ingest.summary_generator')->replaceArgument(2, $fake);
  }

}
