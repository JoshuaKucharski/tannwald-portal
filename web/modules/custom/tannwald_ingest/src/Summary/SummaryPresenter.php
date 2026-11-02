<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Summary;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Chunker;
use Drupal\tannwald_ingest\Form\ReviewForm;
use Drupal\tannwald_ingest\Review\ReviewTransition;

/**
 * Datenhelfer für Theme 5.1 (Dokument) und 5.3 (Review-Karte, Wireframe 03).
 */
class SummaryPresenter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected RendererInterface $renderer,
    protected KeyValueFactoryInterface $keyValue,
    protected Chunker $chunker,
    protected ReviewTransition $reviewTransition,
    protected FormBuilderInterface $formBuilder,
  ) {}

  /**
   * Freigegebene Zusammenfassung zur aktuellen Revision eines Dokuments.
   *
   * @return array|null
   *   {nid, body_html, generiert_am, modell, dokument_revision} oder NULL,
   *   wenn es für die aktuelle Revision in $langcode keine freigegebene gibt
   *   (zur Prüfung, zurückgewiesen, Entwurf erscheinen nie — TC-10).
   */
  public function summaryFor(NodeInterface $dokument, string $langcode): ?array {
    $storage = $this->entityTypeManager->getStorage('node');
    $current = $storage->load($dokument->id());
    if (!$current instanceof NodeInterface) {
      return NULL;
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'zusammenfassung')
      ->condition('field_dokument', $current->id())
      ->condition('field_dokument_revision', $current->getRevisionId())
      ->condition('status', 1, '=', $langcode)
      ->sort('nid', 'DESC')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $summary) {
      if (!$summary->hasTranslation($langcode)) {
        continue;
      }
      $translation = $summary->getTranslation($langcode);
      // Nur published (Standard-Revision, status 1) — zweite Schranke.
      if (!$translation->isPublished()) {
        continue;
      }
      return [
        'nid' => (int) $translation->id(),
        'body_html' => $this->bodyHtml($translation),
        'generiert_am' => $this->generatedAt($translation),
        'modell' => (string) $translation->get('field_modell')->value,
        'dokument_revision' => (int) $translation->get('field_dokument_revision')->value,
      ];
    }
    return NULL;
  }

  /**
   * Review-Karte einer Zusammenfassung (neueste Revision ihrer Sprache).
   *
   * @return array
   *   {nid, vid, dokument: {nid, titel, version, url}, status, generiert_am,
   *   modell, tokens, erstellt_durch, body_html, sicherheitskapitel_genannt,
   *   form}.
   */
  public function reviewCard(NodeInterface $summary): array {
    $langcode = $summary->language()->getId();
    $latest = $this->reviewTransition->latest($summary, $langcode);
    $dokument = $this->documentRevision($latest);
    $meta = $this->keyValue->get(SummaryGenerator::META_COLLECTION)->get($latest->id() . ':' . $langcode) ?: [];
    $author = $dokument?->getRevisionUser();
    return [
      'nid' => (int) $latest->id(),
      'vid' => (int) $latest->getRevisionId(),
      'dokument' => $dokument ? [
        'nid' => (int) $dokument->id(),
        'titel' => (string) $dokument->label(),
        'version' => $dokument->hasField('field_version') ? (string) $dokument->get('field_version')->value : '',
        'url' => $dokument->toUrl()->toString(),
      ] : NULL,
      'status' => (string) $latest->get('moderation_state')->value,
      'generiert_am' => $this->generatedAt($latest),
      'modell' => (string) $latest->get('field_modell')->value,
      'tokens' => isset($meta['tokens']) ? (int) $meta['tokens'] : NULL,
      'erstellt_durch' => $author ? (string) $author->getDisplayName() : '',
      'body_html' => $this->bodyHtml($latest),
      'sicherheitskapitel_genannt' => $dokument ? $this->safetyChaptersMentioned(SummaryText::plain((string) $latest->get('body')->value), $dokument) : FALSE,
      'form' => $this->formBuilder->getForm(ReviewForm::class, $summary),
    ];
  }

  /**
   * Nennt der Text ein Sicherheitskapitel?
   *
   * Heuristik: Wort Sicherheit/Safety/Sécurité oder die Nummer eines
   * Kapitels, dessen heading_path auf SummaryGenerator::SAFETY_PATTERN passt
   * („Kapitel 2“, „2.3“).
   */
  public function safetyChaptersMentioned(string $plain, NodeInterface $dokument): bool {
    if (preg_match('/sicherheit|safety|sécurité|securite/iu', $plain)) {
      return TRUE;
    }
    $chunks = $this->chunker->chunk((string) ($dokument->get('body')->value ?? ''), (string) $dokument->label());
    $numbers = [];
    foreach ($chunks as $chunk) {
      if (!preg_match(SummaryGenerator::SAFETY_PATTERN, $chunk['heading_path'])) {
        continue;
      }
      foreach (explode(Chunker::PATH_SEPARATOR, $chunk['heading_path']) as $element) {
        if (preg_match('/^(\d+(?:\.\d+)*)\s/u', $element, $m)) {
          $numbers[$m[1]] = TRUE;
        }
      }
    }
    foreach (array_keys($numbers) as $number) {
      $quoted = preg_quote((string) $number, '/');
      $pattern = str_contains((string) $number, '.')
        ? '/(?<![\d.])' . $quoted . '(?![\d])/u'
        : '/\b(kapitel|chapter|chapitre|chap\.)\s+' . $quoted . '(?![\d.])/iu';
      if (preg_match($pattern, $plain)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Body als gefiltertes HTML (Format dokument_html).
   */
  protected function bodyHtml(NodeInterface $node): string {
    $build = [
      '#type' => 'processed_text',
      '#text' => (string) $node->get('body')->value,
      '#format' => (string) ($node->get('body')->format ?: 'dokument_html'),
      '#langcode' => $node->language()->getId(),
    ];
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Generiert am, als TT.MM.JJJJ HH:MM (wie Datumsformat tannwald_datum_zeit).
   */
  protected function generatedAt(NodeInterface $node): string {
    $value = (string) $node->get('field_generiert_am')->value;
    if ($value === '') {
      return '';
    }
    $timestamp = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    return $this->dateFormatter->format($timestamp, 'custom', 'd.m.Y H:i');
  }

  /**
   * Die zusammengefasste Dokument-Revision (Sprache der Zusammenfassung).
   */
  protected function documentRevision(NodeInterface $summary): ?NodeInterface {
    $vid = (int) $summary->get('field_dokument_revision')->value;
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $vid ? $storage->loadRevision($vid) : NULL;
    if (!$revision instanceof NodeInterface) {
      $revision = $summary->get('field_dokument')->entity;
    }
    if (!$revision instanceof NodeInterface) {
      return NULL;
    }
    $langcode = $summary->language()->getId();
    return $revision->hasTranslation($langcode) ? $revision->getTranslation($langcode) : $revision;
  }

}
