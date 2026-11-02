<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_assistant\PromptBuilder;
use Drupal\tannwald_ingest\HeadingSlug;

/**
 * Löst erwartete Quellen auf (Vertrag 4.3 d).
 *
 * Dokument → nid (ManifestLookupInterface), version → Revision mit dieser
 * field_version (höchste vid; ohne version die Standard-Revision), kapitel →
 * HeadingSlug, geprüft gegen das letzte heading_path-Element der Chunks dieser
 * Revision (alle Sprachen). Vergleichsschlüssel für Treffer und Zitate: die
 * Kapitelnummer, wenn die Überschrift mit einer Nummer beginnt („7.3“ — gilt
 * für alle Übersetzungen), sonst der Slug.
 */
final class SourceResolver {

  public function __construct(
    private readonly ManifestLookupInterface $manifestLookup,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Löst die Quellen aller Fragen auf; sammelt Fehler je ID.
   *
   * @param \Drupal\tannwald_assistant\Eval\EvalQuestion[] $questions
   *   Fragen.
   *
   * @return array<string, array<int, array{dokument: string, nid: int, vid: int, version: ?string, kapitel: string, slug: string, key: string}>>
   *   Frage-ID => aufgelöste Quellen.
   *
   * @throws \Drupal\tannwald_assistant\Eval\EvalSetException
   *   Mit allen nicht auflösbaren Fragen.
   */
  public function resolveAll(array $questions): array {
    $resolved = [];
    $errors = [];
    foreach ($questions as $question) {
      try {
        $resolved[$question->id] = array_map([$this, 'resolve'], $question->erwarteteQuellen);
      }
      catch (\InvalidArgumentException $e) {
        $errors[$question->id] = $e->getMessage();
      }
    }
    if ($errors !== []) {
      throw new EvalSetException($errors);
    }
    return $resolved;
  }

  /**
   * Löst eine Quelle auf.
   *
   * @param array{dokument: string, version: ?string, kapitel: string} $source
   *   Quelle aus dem Set.
   *
   * @return array{dokument: string, nid: int, vid: int, version: ?string, kapitel: string, slug: string, key: string}
   *   Aufgelöste Quelle.
   */
  public function resolve(array $source): array {
    $nid = $this->manifestLookup->nidOf($source['dokument']);
    $node = $nid === NULL ? NULL : $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException(sprintf('dokument "%s" not found', $source['dokument']));
    }
    $vid = (int) $node->getRevisionId();
    if ($source['version'] !== NULL) {
      if (!$node->hasField('field_version')) {
        throw new \InvalidArgumentException(sprintf('dokument "%s" has no field_version', $source['dokument']));
      }
      $vids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->allRevisions()
        ->accessCheck(FALSE)
        ->condition('nid', $nid)
        ->condition('field_version', $source['version'])
        ->execute();
      if ($vids === []) {
        throw new \InvalidArgumentException(sprintf('dokument "%s" has no revision with version %s', $source['dokument'], $source['version']));
      }
      $vid = (int) max(array_keys($vids));
    }
    $slug = HeadingSlug::of($source['kapitel']);
    $headings = $this->database->select('tannwald_chunk', 'c')
      ->fields('c', ['heading_path'])
      ->condition('nid', $nid)
      ->condition('vid', $vid)
      ->distinct()
      ->execute()
      ->fetchCol();
    $slugs = array_map(static fn (string $path): string => HeadingSlug::of(PromptBuilder::lastHeading($path)), $headings);
    if (!in_array($slug, $slugs, TRUE)) {
      throw new \InvalidArgumentException(sprintf('kapitel "%s" (%s) not among the chunks of %s revision %d', $source['kapitel'], $slug, $source['dokument'], $vid));
    }
    return [
      'dokument' => $source['dokument'],
      'nid' => (int) $nid,
      'vid' => $vid,
      'version' => $source['version'],
      'kapitel' => $source['kapitel'],
      'slug' => $slug,
      'key' => self::chapterKey($source['kapitel']),
    ];
  }

  /**
   * Vergleichsschlüssel eines Kapitels: „num:7.3“ oder „slug:…“.
   */
  public static function chapterKey(string $heading): string {
    if (preg_match('/^\s*(\d+(?:\.\d+)*)(?=\s|$)/u', $heading, $m)) {
      return 'num:' . $m[1];
    }
    return 'slug:' . HeadingSlug::of($heading);
  }

}
