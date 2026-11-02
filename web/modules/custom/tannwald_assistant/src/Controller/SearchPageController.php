<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_assistant\Retriever;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Suche-Seite /suche (Wireframe 02; FA-SE-01/02/04, TC-06; Vertrag 4.2 b).
 *
 * Retriever::search() liefert alle sichtbaren Chunk-Treffer; danach
 * „Nur aktuelle Versionen“ (Chunk-vid = Standard-Revision), Facetten über
 * die verbliebenen sichtbaren Treffer (je Dokument einmal gezählt), dann die
 * Facettenfilter und der Pager (10 je Seite). Ein Treffer je Chunk.
 */
final class SearchPageController implements ContainerInjectionInterface {

  public const PER_PAGE = 10;

  public const SNIPPET_LENGTH = 200;

  public const SORT_RELEVANCE = 'relevanz';

  public const SORT_DATE = 'datum';

  public function __construct(
    private readonly Retriever $retriever,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PagerManagerInterface $pagerManager,
    private readonly AccountInterface $currentUser,
    private readonly ?object $confirmations = NULL,
    private readonly ?LanguageManagerInterface $languageManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tannwald_assistant.retriever'),
      $container->get('entity_type.manager'),
      $container->get('pager.manager'),
      $container->get('current_user'),
      // C's Bestätigungen (2.5), optional.
      $container->has('tannwald_bulletin.confirmations') ? $container->get('tannwald_bulletin.confirmations') : NULL,
      $container->get('language_manager'),
    );
  }

  /**
   * GET /suche?q=&modus=&dokumenttyp[]=&maschinentyp[]=&sprache[]=&aktuell=1&maschine_nid=&page=.
   */
  public function build(Request $request): array {
    $q = trim((string) $request->query->get('q', ''));
    $modus = $request->query->get('modus') === RetrievalResult::MODE_FULLTEXT ? RetrievalResult::MODE_FULLTEXT : RetrievalResult::MODE_HYBRID;
    $aktuell = (string) $request->query->get('aktuell', '') === '1';
    $sort = $request->query->get('sort') === self::SORT_DATE ? self::SORT_DATE : self::SORT_RELEVANCE;
    $filters = [
      'dokumenttyp' => array_map('intval', self::listParam($request, 'dokumenttyp')),
      'maschinentyp' => array_map('intval', self::listParam($request, 'maschinentyp')),
      'sprache' => array_map('strval', self::listParam($request, 'sprache')),
    ];
    $maschineNid = ctype_digit((string) $request->query->get('maschine_nid', '')) ? (int) $request->query->get('maschine_nid') : NULL;

    $hits = [];
    $dauerMs = 0;
    if ($q !== '') {
      $result = $this->retriever->search($q, $this->currentUser, $maschineNid, $modus);
      $hits = $result->hits;
      $dauerMs = $result->dauerMs;
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_values(array_unique(array_column($hits, 'nid'))));
    if ($aktuell) {
      $hits = array_values(array_filter($hits, static fn (array $hit): bool => isset($nodes[$hit['nid']]) && (int) $nodes[$hit['nid']]->getRevisionId() === (int) $hit['vid']));
    }
    $facets = $this->facets($hits, $filters);
    $hits = array_values(array_filter($hits, static fn (array $hit): bool => self::passes($hit, $filters)));
    if ($sort === self::SORT_DATE) {
      $hits = self::sortByDate($hits, $nodes);
    }

    $total = count($hits);
    $pager = $this->pagerManager->createPager($total, self::PER_PAGE);
    $pageHits = array_slice($hits, $pager->getCurrentPage() * self::PER_PAGE, self::PER_PAGE);

    return [
      '#theme' => 'tannwald_search_page',
      '#query' => $q,
      '#modus' => $modus,
      '#aktuell' => $aktuell,
      '#filters' => $filters,
      '#hits' => $this->renderHits($pageHits, $nodes, $q),
      '#facets' => $facets,
      '#total' => $total,
      '#dauer_ms' => $dauerMs,
      '#pager' => ['#type' => 'pager'],
      '#live_url' => Url::fromRoute('tannwald_assistant.api_live')->toString(),
      '#sort' => $sort,
      '#sort_urls' => $this->sortUrls($request),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Treffer für das Template (Felder nach Vertrag 4.2 b).
   *
   * @param array<int, array> $hits
   *   Treffer der Seite.
   * @param array<int, \Drupal\node\NodeInterface> $nodes
   *   Geladene Nodes nach nid.
   * @param string $q
   *   Suchtext (für <mark>).
   */
  private function renderHits(array $hits, array $nodes, string $q): array {
    $tids = [];
    foreach ($hits as $hit) {
      $tids[] = (int) $hit['dokumenttyp_tid'];
      array_push($tids, ...array_map('intval', $hit['maschinentyp_tids']));
    }
    $labels = $this->termLabels($tids);
    $items = [];
    foreach ($hits as $hit) {
      $node = $nodes[$hit['nid']] ?? NULL;
      $machine = $node instanceof NodeInterface && $node->hasField('field_seriennummer') ? $node->get('field_seriennummer')->entity : NULL;
      $items[] = [
        'nid' => (int) $hit['nid'],
        'titel' => (string) $hit['title'],
        'heading_path' => (string) $hit['heading_path'],
        'snippet_html' => self::snippet((string) $hit['text'], $q),
        'langcode' => (string) $hit['langcode'],
        'dokumenttyp' => $labels[(int) $hit['dokumenttyp_tid']] ?? '',
        'maschinentypen' => array_values(array_filter(array_map(static fn (int $tid): string => $labels[$tid] ?? '', $hit['maschinentyp_tids']))),
        'version' => (string) $hit['version'],
        'freigegeben_am' => $node instanceof NodeInterface ? self::date($node, 'field_freigegeben_am') : NULL,
        'url_kapitel' => (string) $hit['url'],
        'url_dokument' => strtok((string) $hit['url'], '#') ?: (string) $hit['url'],
        'url_maschine' => $machine instanceof NodeInterface && $machine->access('view', $this->currentUser) ? $machine->toUrl('canonical', $this->uiLanguage())->toString() : NULL,
        'maschine_titel' => $machine instanceof NodeInterface && $machine->access('view', $this->currentUser) ? (string) $machine->label() : NULL,
        'matched_by' => (string) $hit['matched_by'],
        'bulletin_bestaetigt_am' => $node instanceof NodeInterface ? $this->confirmedAt($node) : NULL,
      ];
    }
    return $items;
  }

  /**
   * Facetten dokumenttyp, maschinentyp, sprache je {id, label, count, active}.
   *
   * Gezählt über die sichtbaren Treffer (nach „aktuell“, vor den
   * Facettenfiltern), je Dokument (nid, vid, langcode) einmal.
   */
  private function facets(array $hits, array $filters): array {
    $counts = ['dokumenttyp' => [], 'maschinentyp' => [], 'sprache' => []];
    $seen = [];
    foreach ($hits as $hit) {
      $key = $hit['nid'] . ':' . $hit['vid'] . ':' . $hit['langcode'];
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      if ((int) $hit['dokumenttyp_tid'] > 0) {
        $counts['dokumenttyp'][(int) $hit['dokumenttyp_tid']] = ($counts['dokumenttyp'][(int) $hit['dokumenttyp_tid']] ?? 0) + 1;
      }
      foreach (array_unique(array_map('intval', $hit['maschinentyp_tids'])) as $tid) {
        $counts['maschinentyp'][$tid] = ($counts['maschinentyp'][$tid] ?? 0) + 1;
      }
      $counts['sprache'][(string) $hit['langcode']] = ($counts['sprache'][(string) $hit['langcode']] ?? 0) + 1;
    }
    $labels = $this->termLabels(array_merge(array_keys($counts['dokumenttyp']), array_keys($counts['maschinentyp'])));
    $languages = LanguageManager::getStandardLanguageList();
    $facets = [];
    foreach ($counts as $name => $values) {
      $facets[$name] = [];
      foreach ($values as $id => $count) {
        $label = $name === 'sprache' ? ($languages[$id][1] ?? (string) $id) : ($labels[(int) $id] ?? (string) $id);
        $facets[$name][] = [
          'id' => $id,
          'label' => $label,
          'count' => $count,
          'active' => in_array($id, $filters[$name], TRUE),
        ];
      }
      // Absteigend nach Zahl, dann nach Label.
      usort($facets[$name], static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp((string) $a['label'], (string) $b['label']));
    }
    return $facets;
  }

  /**
   * TRUE, wenn der Treffer alle gesetzten Facettenfilter erfüllt.
   */
  private static function passes(array $hit, array $filters): bool {
    if ($filters['dokumenttyp'] !== [] && !in_array((int) $hit['dokumenttyp_tid'], $filters['dokumenttyp'], TRUE)) {
      return FALSE;
    }
    if ($filters['maschinentyp'] !== [] && array_intersect($filters['maschinentyp'], array_map('intval', $hit['maschinentyp_tids'])) === []) {
      return FALSE;
    }
    return $filters['sprache'] === [] || in_array((string) $hit['langcode'], $filters['sprache'], TRUE);
  }

  /**
   * Sortierung „Datum“: freigegeben_am absteigend, sonst Änderungsdatum.
   *
   * Stabil: bei gleichem Datum bleibt die Relevanz-Reihenfolge.
   *
   * @param array<int, array> $hits
   *   Treffer in Relevanz-Reihenfolge.
   * @param array<int, \Drupal\node\NodeInterface> $nodes
   *   Geladene Nodes nach nid.
   */
  private static function sortByDate(array $hits, array $nodes): array {
    $keyed = [];
    foreach ($hits as $position => $hit) {
      $node = $nodes[$hit['nid']] ?? NULL;
      $date = 0;
      if ($node instanceof NodeInterface) {
        $released = $node->hasField('field_freigegeben_am') && !$node->get('field_freigegeben_am')->isEmpty() ? strtotime((string) $node->get('field_freigegeben_am')->value) : FALSE;
        $date = $released !== FALSE ? (int) $released : (int) $node->getChangedTime();
      }
      $keyed[] = [$date, $position, $hit];
    }
    usort($keyed, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
    return array_column($keyed, 2);
  }

  /**
   * Links für beide Sortierungen mit den übrigen Parametern, ohne page.
   *
   * @return array<string, string>
   *   relevanz|datum => URL.
   */
  private function sortUrls(Request $request): array {
    $query = $request->query->all();
    unset($query['page']);
    $urls = [];
    foreach ([self::SORT_RELEVANCE, self::SORT_DATE] as $sort) {
      $urls[$sort] = Url::fromRoute('tannwald_assistant.search', [], ['query' => ['sort' => $sort] + $query])->toString();
    }
    return $urls;
  }

  /**
   * URL-Option: Präfix der Oberflächensprache (6.1).
   *
   * Ein Klick auf den Link wechselt die Sprache dann nicht.
   *
   * @return array<string, mixed>
   *   Optionen für toUrl().
   */
  private function uiLanguage(): array {
    return $this->languageManager === NULL ? [] : ['language' => $this->languageManager->getCurrentLanguage()];
  }

  /**
   * Snippet: 200 Zeichen um den ersten Suchbegriff, Begriffe in <mark>.
   */
  public static function snippet(string $text, string $q): MarkupInterface|string {
    $lines = preg_split('/\R/u', trim($text)) ?: [];
    // Erste Zeile ist die Überschrift (Chunker), steht schon im heading_path.
    $body = count($lines) > 1 ? implode(' ', array_slice($lines, 1)) : implode(' ', $lines);
    $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    $terms = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [], static fn (string $t): bool => mb_strlen($t) >= 3));
    $start = 0;
    foreach ($terms as $term) {
      $pos = mb_stripos($body, $term);
      if ($pos !== FALSE) {
        $start = max(0, $pos - 60);
        break;
      }
    }
    $excerpt = mb_substr($body, $start, self::SNIPPET_LENGTH);
    $html = ($start > 0 ? '… ' : '') . Html::escape($excerpt) . ($start + self::SNIPPET_LENGTH < mb_strlen($body) ? ' …' : '');
    if ($terms !== []) {
      $pattern = '/(' . implode('|', array_map(static fn (string $t): string => preg_quote(Html::escape($t), '/'), $terms)) . ')/iu';
      $html = preg_replace($pattern, '<mark>$1</mark>', $html) ?? $html;
    }
    return Markup::create($html);
  }

  /**
   * Datumsfeld als TT.MM.JJJJ oder NULL.
   */
  private static function date(NodeInterface $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $node->get($field)->value, 0, 10));
    return $date ? $date->format('d.m.Y') : NULL;
  }

  /**
   * Bestätigt am (Bulletins, C's ConfirmationService) als TT.MM.JJJJ oder NULL.
   */
  private function confirmedAt(NodeInterface $node): ?string {
    if ($node->bundle() !== 'bulletin' || $this->confirmations === NULL || !method_exists($this->confirmations, 'confirmedAt')) {
      return NULL;
    }
    $confirmation = $this->confirmations->confirmedAt($node, $this->currentUser);
    return is_array($confirmation) && isset($confirmation['bestaetigt_am']) ? date('d.m.Y', (int) $confirmation['bestaetigt_am']) : NULL;
  }

  /**
   * Term-Labels nach tid.
   *
   * @param int[] $tids
   *   Term-IDs.
   *
   * @return array<int, string>
   *   Labels.
   */
  private function termLabels(array $tids): array {
    $tids = array_values(array_filter(array_unique(array_map('intval', $tids))));
    $labels = [];
    if ($tids !== []) {
      foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids) as $tid => $term) {
        $labels[(int) $tid] = (string) $term->label();
      }
    }
    return $labels;
  }

  /**
   * Mehrwertiger Query-Parameter (name[]=…) als Liste.
   *
   * @return array<int, string>
   *   Werte.
   */
  private static function listParam(Request $request, string $name): array {
    $value = $request->query->all()[$name] ?? [];
    $values = is_array($value) ? $value : [$value];
    return array_values(array_filter(array_map('strval', $values), static fn (string $v): bool => $v !== ''));
  }

}
