<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Render\Markup;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Summary\SummaryPresenter;

/**
 * Pseudo-Feld „Zusammenfassung“ am Dokument und Theme-Hook tannwald_summary.
 */
class SummaryHooks {

  use StringTranslationTrait;

  public function __construct(
    protected SummaryPresenter $presenter,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['node']['dokument']['display']['tannwald_summary'] = [
      'label' => $this->t('Approved summary'),
      'description' => $this->t('Approved AI summary of the current revision.'),
      'weight' => 5,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for node.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($node->bundle() !== 'dokument' || !$display->getComponent('tannwald_summary')) {
      return;
    }
    $summary = $this->presenter->summaryFor($node, $node->language()->getId());
    $element = $summary === NULL ? [] : [
      '#theme' => 'tannwald_summary',
      '#body' => Markup::create($summary['body_html']),
      '#generiert_am' => $summary['generiert_am'],
      '#modell' => $summary['modell'],
      '#summary_nid' => $summary['nid'],
    ];
    // Neue Freigaben oder Zurückweisungen müssen das Dokument neu rendern.
    $cache = (new CacheableMetadata())->addCacheTags(['node_list:zusammenfassung']);
    if ($summary !== NULL) {
      $cache->addCacheTags(['node:' . $summary['nid']]);
    }
    $cache->applyTo($element);
    $build['tannwald_summary'] = $element;
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'tannwald_summary' => [
        'variables' => [
          'body' => NULL,
          'generiert_am' => '',
          'modell' => '',
          'summary_nid' => NULL,
        ],
      ],
    ];
  }

}
