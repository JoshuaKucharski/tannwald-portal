<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Ersetzt Embedding und Chat des Assistenten durch Doubles.
 *
 * Ai.provider selbst bleibt unverändert (andere Dienste tippen die finale
 * Klasse); nur das Argument von tannwald_assistant.answer wird umgehängt.
 */
final class TannwaldAssistantTestServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('tannwald_ingest.embedding')
      ->setClass(FakeEmbeddingService::class)
      ->setArguments([new Reference('state')]);
    $container->register('tannwald_assistant_test.chat_manager', FakeChatManager::class)
      ->addArgument(new Reference('state'));
    $answer = $container->getDefinition('tannwald_assistant.answer');
    foreach ($answer->getArguments() as $index => $argument) {
      if ($argument instanceof Reference && (string) $argument === 'ai.provider') {
        $answer->replaceArgument($index, new Reference('tannwald_assistant_test.chat_manager'));
      }
    }
  }

}
