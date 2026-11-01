<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Plugin\views\area;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tannwald_bulletin\ConfirmationService;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Zahl und Liste der offenen Lesebestätigungen des Bulletins (Argument 1).
 */
#[ViewsArea('tannwald_bulletin_open_confirmations')]
final class OpenConfirmations extends AreaPluginBase {

  /**
   * Dienst der Lesebestätigungen.
   */
  protected ConfirmationService $confirmations;

  /**
   * Entity-Typ-Manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->confirmations = $container->get('tannwald_bulletin.confirmations');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Render-Array.
   */
  public function render($empty = FALSE): array {
    $nid = (int) ($this->view->args[0] ?? 0);
    $bulletin = $nid > 0 ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if (!$bulletin instanceof NodeInterface) {
      return [];
    }
    $items = [];
    foreach ($this->confirmations->openConfirmations($bulletin) as $recipient) {
      $serials = array_map(static fn(NodeInterface $machine): string => (string) $machine->label(), $recipient['maschinen']);
      $items[] = $recipient['account']->getDisplayName() . ': ' . implode(', ', $serials);
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['tannwald-open-confirmations']],
      'count' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => (string) new TranslatableMarkup('Open: @count', ['@count' => count($items)]),
      ],
      'list' => ['#theme' => 'item_list', '#items' => $items],
      '#cache' => ['max-age' => 0],
    ];
  }

}
