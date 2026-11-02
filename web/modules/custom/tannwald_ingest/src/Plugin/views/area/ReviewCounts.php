<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Plugin\views\area;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reiter „Zur Prüfung · n | Freigegeben · n | Zurückgewiesen · n“.
 *
 * Zählt die Zusammenfassungen je Moderationsstatus der Standard-Revision
 * (eine Zeile je Übersetzung, wie die View) und verlinkt die Anzeige gleichen
 * Namens; die Anzeige-IDs der View sind die Status-IDs.
 */
#[ViewsArea('tannwald_review_counts')]
final class ReviewCounts extends AreaPluginBase {

  /**
   * Status-ID => Anzeige-ID der View redaktion_freigaben.
   */
  public const STATES = ['zur_pruefung', 'published', 'zurueckgewiesen'];

  /**
   * Datenbank.
   */
  protected Connection $database;

  /**
   * Entity-Typ-Manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = new self($configuration, $plugin_id, $plugin_definition);
    $instance->database = $container->get('database');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    $counts = self::counts($this->database);
    $workflow = $this->entityTypeManager->getStorage('workflow')->load('redaktion');
    $items = [];
    foreach (self::STATES as $state) {
      $label = $workflow?->getTypePlugin()->hasState($state) ? $workflow->getTypePlugin()->getState($state)->label() : $state;
      $text = $label . ' · ' . ($counts[$state] ?? 0);
      $item = $this->view->displayHandlers->has($state) && $this->view->displayHandlers->get($state)->hasPath()
        ? Link::fromTextAndUrl($text, Url::fromRoute('view.' . $this->view->id() . '.' . $state))->toRenderable()
        : ['#markup' => $text];
      $item['#wrapper_attributes']['class'][] = $state === $this->view->current_display ? 'is-active' : 'tannwald-review-tab';
      $items[] = $item;
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['tannwald-review-tabs']],
      '#cache' => ['tags' => ['node_list:zusammenfassung', 'config:workflows.workflow.redaktion']],
    ];
  }

  /**
   * Anzahl Zusammenfassungen (Übersetzungen) je Moderationsstatus.
   *
   * @return array<string, int>
   *   Status-ID => Anzahl.
   */
  public static function counts(Connection $database): array {
    $query = $database->select('content_moderation_state_field_data', 'm');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.content_entity_id AND n.vid = m.content_entity_revision_id AND n.langcode = m.langcode');
    $query->addField('m', 'moderation_state');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('m.content_entity_type_id', 'node')
      ->condition('m.workflow', 'redaktion')
      ->condition('n.type', 'zusammenfassung')
      ->groupBy('m.moderation_state');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

}
