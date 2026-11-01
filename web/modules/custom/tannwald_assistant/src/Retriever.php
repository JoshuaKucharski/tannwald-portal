<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_assistant\Access\AccessKeysInterface;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_ingest\Embedding\EmbeddingService;
use Drupal\tannwald_ingest\HeadingSlug;
use Drupal\tannwald_ingest\Store\ChunkStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Hybrides Retrieval (Pflichtenheft §6.3, §8; RunBook §1.1).
 *
 * Keys des Nutzers → Keyword Top 20 (+ Vektor Top 20 bei hybrid) → RRF k=60 →
 * Maschinenfilter → min_score → Facetten über alle verbliebenen Treffer →
 * Top top_k mit $node->access('view') je Treffer (zweite Schranke).
 */
final class Retriever {

  /**
   * Kandidaten je Liste (Keyword und Vektor).
   */
  public const CANDIDATES = 20;

  /**
   * Kandidaten je Liste für search() (Suche-Seite, Live-Suche).
   */
  public const SEARCH_CANDIDATES = 100;

  public function __construct(
    private readonly AccessKeysInterface $accessKeys,
    private readonly ChunkStoreInterface $store,
    private readonly EmbeddingService $embedding,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageDetector $languageDetector,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sucht die besten zugänglichen Chunks zu einer Frage.
   *
   * @param string $frage
   *   Frage oder Suchtext.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Konto, dessen Keys und Zugriff gelten.
   * @param int|null $maschineNid
   *   Maschine (nid): nur Treffer ihres Typs oder ihrer Seriennummer.
   * @param string $modus
   *   hybrid (Keyword + Vektor) oder volltext (nur Keyword).
   */
  public function retrieve(string $frage, AccountInterface $account, ?int $maschineNid, string $modus = RetrievalResult::MODE_HYBRID): RetrievalResult {
    $start = hrtime(TRUE);
    [$langcode, $fused] = $this->candidates($frage, $account, $maschineNid, $modus, self::CANDIDATES, TRUE);
    if ($fused === NULL) {
      return RetrievalResult::empty($frage, $langcode, $modus, self::elapsedMs($start));
    }

    $settings = $this->configFactory->get('tannwald_assistant.settings');
    $topK = max(1, (int) ($settings->get('top_k') ?? 6));
    $minScore = (float) ($settings->get('min_score') ?? 0.015);
    $fused = array_values(array_filter($fused, static fn (array $row): bool => $row['score'] >= $minScore));

    $facets = RetrievalResult::EMPTY_FACETS;
    if ($fused !== []) {
      $facets = $this->store->facetCounts(array_map(static fn (array $row): int => (int) $row['id'], $fused)) + $facets;
    }

    $hits = [];
    foreach ($fused as $row) {
      if (count($hits) >= $topK) {
        break;
      }
      $hit = $this->buildHit($row, $account);
      if ($hit !== NULL) {
        $hits[] = $hit;
      }
    }
    return new RetrievalResult($frage, $langcode, $modus, $hits, $facets, self::elapsedMs($start));
  }

  /**
   * Alle sichtbaren Treffer für die Suche-Seite und die Live-Suche (4.2).
   *
   * Wie retrieve(), aber Keyword Top $candidates (+ Vektor Top $candidates),
   * ohne min_score und ohne Top-k-Schnitt; $node->access('view') für jeden
   * Treffer; Facetten nur über die sichtbaren Treffer (FA-SE-04).
   *
   * @param string $frage
   *   Suchtext.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Konto, dessen Keys und Zugriff gelten.
   * @param int|null $maschineNid
   *   Maschine (nid) wie bei retrieve().
   * @param string $modus
   *   Modus: hybrid oder volltext.
   * @param int $candidates
   *   Kandidaten je Liste (Standard 100).
   */
  public function search(string $frage, AccountInterface $account, ?int $maschineNid, string $modus = RetrievalResult::MODE_HYBRID, int $candidates = self::SEARCH_CANDIDATES): RetrievalResult {
    $start = hrtime(TRUE);
    [$langcode, $fused] = $this->candidates($frage, $account, $maschineNid, $modus, max(1, $candidates));
    if ($fused === NULL) {
      return RetrievalResult::empty($frage, $langcode, $modus, self::elapsedMs($start));
    }
    // Nodes einmal laden; buildHit() liest danach aus dem Entity-Cache.
    $this->entityTypeManager->getStorage('node')->loadMultiple(array_values(array_unique(array_map(static fn (array $row): int => (int) $row['nid'], $fused))));
    $hits = [];
    foreach ($fused as $row) {
      $hit = $this->buildHit($row, $account);
      if ($hit !== NULL) {
        $hits[] = $hit;
      }
    }
    $facets = RetrievalResult::EMPTY_FACETS;
    if ($hits !== []) {
      $facets = $this->store->facetCounts(array_column($hits, 'chunk_id')) + $facets;
    }
    return new RetrievalResult($frage, $langcode, $modus, $hits, $facets, self::elapsedMs($start));
  }

  /**
   * Kandidaten: Keys → Keyword (+ Vektor) → [aktuelle Revision] → RRF → Maschine.
   *
   * @param bool $currentOnly
   *   TRUE (retrieve): nur Chunks der Standard-Revision je Node, gefiltert je
   *   Liste vor der Fusion (Entscheid des Masters 4.2/4.3); search() lässt
   *   ältere veröffentlichte Revisionen zu (Schalter „Nur aktuelle Versionen“).
   *
   * @return array{0: string, 1: array<int, array>|null}
   *   Erkannte Sprache und fusionierte Zeilen; NULL ohne Keys oder Frage.
   */
  private function candidates(string $frage, AccountInterface $account, ?int $maschineNid, string $modus, int $limit, bool $currentOnly = FALSE): array {
    if (!in_array($modus, [RetrievalResult::MODE_HYBRID, RetrievalResult::MODE_FULLTEXT], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown retrieval mode "%s".', $modus));
    }
    $langcode = $this->languageDetector->detect($frage, $account->getPreferredLangcode());
    $keys = $this->accessKeys->forAccount($account);
    if ($keys === [] || trim($frage) === '') {
      return [$langcode, NULL];
    }
    $lists = ['keyword' => $this->store->keyword($frage, $keys, $limit)];
    if ($modus === RetrievalResult::MODE_HYBRID) {
      try {
        $vector = $this->embedding->embed([$frage], 'assistent')[0] ?? [];
        if ($vector !== []) {
          $lists['vector'] = $this->store->similar($vector, $keys, $limit);
        }
      }
      catch (\Throwable $e) {
        // Ohne Embedding bleibt der Keyword-Zweig; die Antwort geht weiter.
        $this->logger->warning('Query embedding failed, keyword branch only: @message', ['@message' => $e->getMessage()]);
      }
    }
    if ($currentOnly) {
      $lists = $this->currentRevisionsOnly($lists);
    }
    $fused = Rrf::fuse($lists, Rrf::DEFAULT_K);
    if ($maschineNid !== NULL) {
      $fused = $this->filterByMachine($fused, $maschineNid);
    }
    return [$langcode, $fused];
  }

  /**
   * Entfernt Zeilen, deren vid nicht die Standard-Revision des Nodes ist.
   *
   * @param array<string, array<int, array>> $lists
   *   Trefferlisten je Zweig.
   *
   * @return array<string, array<int, array>>
   *   Gefilterte Listen (Rangfolge bleibt).
   */
  private function currentRevisionsOnly(array $lists): array {
    $nids = [];
    foreach ($lists as $rows) {
      foreach ($rows as $row) {
        $nids[(int) $row['nid']] = TRUE;
      }
    }
    if ($nids === []) {
      return $lists;
    }
    $current = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($nids)) as $nid => $node) {
      $current[(int) $nid] = (int) $node->getRevisionId();
    }
    foreach ($lists as $name => $rows) {
      $lists[$name] = array_values(array_filter($rows, static fn (array $row): bool => ($current[(int) $row['nid']] ?? NULL) === (int) $row['vid']));
    }
    return $lists;
  }

  /**
   * Prüft Zugriff auf die Trefferübersetzung und ergänzt Version und URL.
   */
  private function buildHit(array $row, AccountInterface $account): ?array {
    $nid = (int) $row['nid'];
    $langcode = (string) $row['langcode'];
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$node->hasTranslation($langcode)) {
      $this->logger->notice('Retriever dropped chunk @id: node @nid (@langcode) not found.', [
        '@id' => $row['id'],
        '@nid' => $nid,
        '@langcode' => $langcode,
      ]);
      return NULL;
    }
    $translation = $node->getTranslation($langcode);
    if (!$translation->access('view', $account)) {
      // Darf nach dem access_keys-Vorfilter nie passieren: Drift zwischen
      // Chunk-Keys und Grants.
      $this->logger->warning('Retriever dropped hit without view access: nid @nid (@langcode), uid @uid; access_keys and grants drift.', [
        '@nid' => $nid,
        '@langcode' => $langcode,
        '@uid' => $account->id(),
      ]);
      return NULL;
    }

    $headingPath = (string) $row['heading_path'];
    $options = [];
    $lastHeading = PromptBuilder::lastHeading($headingPath);
    if ($lastHeading !== '') {
      $options['fragment'] = 'kapitel-' . HeadingSlug::of($lastHeading);
    }
    return [
      'chunk_id' => (int) $row['id'],
      'nid' => $nid,
      'vid' => (int) $row['vid'],
      'langcode' => $langcode,
      'chunk_index' => (int) $row['chunk_index'],
      'heading_path' => $headingPath,
      'title' => (string) $row['title'],
      'version' => $this->versionOf($translation, (int) $row['vid']),
      'text' => (string) $row['text'],
      'dokumenttyp_tid' => (int) ($row['dokumenttyp_tid'] ?? 0),
      'maschinentyp_tids' => array_map('intval', (array) ($row['maschinentyp_tids'] ?? [])),
      'exportklasse' => (string) ($row['exportklasse'] ?? ''),
      'score' => (float) $row['score'],
      'matched_by' => (string) $row['matched_by'],
      'url' => $translation->toUrl('canonical', $options)->toString(),
    ];
  }

  /**
   * Field_version der Chunk-Revision (Übersetzung), sonst leer.
   */
  private function versionOf(NodeInterface $translation, int $vid): string {
    if (!$translation->hasField('field_version')) {
      return '';
    }
    $source = $translation;
    if ($vid > 0 && (int) $translation->getRevisionId() !== $vid) {
      $revision = $this->entityTypeManager->getStorage('node')->loadRevision($vid);
      if ($revision instanceof NodeInterface && $revision->hasTranslation($translation->language()->getId())) {
        $source = $revision->getTranslation($translation->language()->getId());
      }
    }
    return (string) ($source->get('field_version')->value ?? '');
  }

  /**
   * Vorfilter über die Trefferliste: Typ der Maschine oder ihre Seriennummer.
   *
   * Kein zweiter SQL-Suchweg: die fusionierte Liste wird gefiltert. Ein
   * anlagenspezifisches Dokument (field_seriennummer gesetzt) bleibt nur für
   * genau diese Maschine; alle anderen Treffer brauchen einen gemeinsamen
   * Maschinentyp (maschinentyp_tids des Chunks ∩ field_maschinentyp).
   */
  private function filterByMachine(array $fused, int $maschineNid): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $machine = $storage->load($maschineNid);
    if (!$machine instanceof NodeInterface || !$machine->hasField('field_maschinentyp')) {
      return [];
    }
    $typeTids = array_map('intval', array_column($machine->get('field_maschinentyp')->getValue(), 'target_id'));
    $serialTargets = $this->serialTargets(array_values(array_unique(array_map(static fn (array $row): int => (int) $row['nid'], $fused))));

    return array_values(array_filter($fused, static function (array $row) use ($typeTids, $serialTargets, $maschineNid): bool {
      $nid = (int) $row['nid'];
      if (isset($serialTargets[$nid])) {
        return in_array($maschineNid, $serialTargets[$nid], TRUE);
      }
      return array_intersect($typeTids, array_map('intval', (array) ($row['maschinentyp_tids'] ?? []))) !== [];
    }));
  }

  /**
   * Seriennummer-Ziele der Dokumente mit field_seriennummer.
   *
   * @param int[] $nids
   *   Kandidaten.
   *
   * @return array<int, int[]>
   *   nid => Maschinen-nids (nur Dokumente mit gesetztem Feld).
   */
  private function serialTargets(array $nids): array {
    if ($nids === [] || !isset($this->entityFieldManager->getFieldStorageDefinitions('node')['field_seriennummer'])) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $withSerial = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $nids, 'IN')
      ->exists('field_seriennummer')
      ->execute();
    $targets = [];
    foreach ($storage->loadMultiple($withSerial) as $node) {
      if ($node->hasField('field_seriennummer')) {
        $targets[(int) $node->id()] = array_map('intval', array_column($node->get('field_seriennummer')->getValue(), 'target_id'));
      }
    }
    return $targets;
  }

  /**
   * Millisekunden seit $start (hrtime).
   */
  private static function elapsedMs(int|float $start): int {
    return (int) round((hrtime(TRUE) - $start) / 1e6);
  }

}
