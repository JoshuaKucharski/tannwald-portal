<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\tannwald_assistant\AnswerService;
use Drupal\tannwald_assistant\Entity\SupportAnfrage;
use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_assistant\Retriever;
use Drupal\tannwald_assistant\Ticket\TicketSinkInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Portal-API des Assistenten (Pflichtenheft §7, §9; Vertrag 4.2 §3.4 a).
 *
 * Session-Auth (_user_is_logged_in), CSRF-Header X-CSRF-Token von
 * /session/token für POST, Flood je uid. Antworten immer JSON.
 */
final class AssistantApiController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public const FLOOD_ASK = 'tannwald_assistant.ask';

  public const FLOOD_ASK_LIMIT = 10;

  public const FLOOD_LIVE = 'tannwald_assistant.live';

  public const FLOOD_LIVE_LIMIT = 30;

  public const FLOOD_WINDOW = 60;

  public const LIVE_TOP = 5;

  public const LIVE_CANDIDATES = 20;

  public const HISTORY_SIZE = 5;

  public function __construct(
    private readonly AnswerService $answerService,
    private readonly Retriever $retriever,
    private readonly FloodInterface $flood,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketSinkInterface $ticketSink,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tannwald_assistant.answer'),
      $container->get('tannwald_assistant.retriever'),
      $container->get('flood'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('tannwald_assistant.ticket_sink'),
      $container->get('current_user'),
    );
  }

  /**
   * POST /api/assistant {frage, maschine_nid?, session}.
   */
  public function ask(Request $request): JsonResponse {
    $identifier = 'uid:' . $this->currentUser->id();
    if (!$this->flood->isAllowed(self::FLOOD_ASK, self::FLOOD_ASK_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return $this->error(429, $this->t('Too many questions. Please wait a minute.'));
    }
    $this->flood->register(self::FLOOD_ASK, self::FLOOD_WINDOW, $identifier);

    $data = self::body($request);
    $frage = $data['frage'] ?? NULL;
    if (!is_string($frage) || trim($frage) === '') {
      return $this->error(400, $this->t('Please enter a question.'));
    }
    if (mb_strlen($frage) > AnswerService::MAX_LENGTH) {
      return $this->error(400, $this->t('The question is longer than 1,000 characters.'));
    }
    $maschineNid = self::optionalId($data['maschine_nid'] ?? NULL);
    if ($maschineNid !== NULL && !$this->machineVisible($maschineNid)) {
      return $this->error(404, $this->t('Machine not found.'));
    }
    $session = is_string($data['session'] ?? NULL) ? mb_substr($data['session'], 0, 128) : '';

    $result = $this->answerService->answer($frage, $this->currentUser, $maschineNid, $session);
    if ($result->status === AnswerResult::STATUS_LIMIT) {
      return new JsonResponse([
        'status' => AnswerResult::STATUS_LIMIT,
        'antwort_html' => '',
        'quellen' => [],
        'log_id' => $result->logId,
        'hinweis' => trim(html_entity_decode(strip_tags($result->antwortHtml), ENT_QUOTES)),
      ], 503);
    }
    return new JsonResponse($result->toArray(), 200);
  }

  /**
   * POST /api/assistant/feedback {log_id, bewertung: 1|-1}.
   */
  public function feedback(Request $request): JsonResponse {
    $data = self::body($request);
    $logId = self::optionalId($data['log_id'] ?? NULL);
    $bewertung = $data['bewertung'] ?? NULL;
    if ($logId === NULL || !in_array($bewertung, [1, -1], TRUE)) {
      return $this->error(400, $this->t('Expected log_id and bewertung 1 or -1.'));
    }
    if ($this->ownLogRow($logId) === NULL) {
      return $this->error(404, $this->t('Answer not found.'));
    }
    $this->database->update(AnswerService::LOG_TABLE)
      ->fields(['bewertung' => $bewertung])
      ->condition('id', $logId)
      ->execute();
    return new JsonResponse(['ok' => TRUE], 200);
  }

  /**
   * POST /api/assistant/support {log_id, maschine_nid?} → support_anfrage.
   */
  public function support(Request $request): JsonResponse {
    $data = self::body($request);
    $logId = self::optionalId($data['log_id'] ?? NULL);
    if ($logId === NULL) {
      return $this->error(400, $this->t('Expected log_id.'));
    }
    $row = $this->ownLogRow($logId);
    if ($row === NULL) {
      return $this->error(404, $this->t('Answer not found.'));
    }
    $maschineNid = self::optionalId($data['maschine_nid'] ?? NULL) ?? self::optionalId($row['maschine_nid']);
    if ($maschineNid !== NULL && !$this->machineVisible($maschineNid)) {
      return $this->error(404, $this->t('Machine not found.'));
    }

    $history = [];
    $rows = $this->database->select(AnswerService::LOG_TABLE, 'l')
      ->fields('l', ['id', 'frage', 'status', 'antwort', 'created'])
      ->condition('session_hash', $row['session_hash'])
      ->condition('uid_hash', $row['uid_hash'])
      ->condition('id', $logId, '<=')
      ->orderBy('id', 'DESC')
      ->range(0, self::HISTORY_SIZE)
      ->execute()
      ->fetchAll(FetchAs::Associative);
    foreach (array_reverse($rows) as $entry) {
      $history[] = [
        'log_id' => (int) $entry['id'],
        'frage' => (string) $entry['frage'],
        'status' => (string) $entry['status'],
        'antwort' => mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('</p>', "</p>\n", (string) $entry['antwort'])), ENT_QUOTES)) ?? ''), 0, 300),
        'zeit' => date('c', (int) $entry['created']),
      ];
    }

    $supportAnfrage = SupportAnfrage::create([
      'uid' => $this->currentUser->id(),
      'maschine_nid' => $maschineNid,
      'frage' => (string) $row['frage'],
      'verlauf' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'status' => SupportAnfrage::STATUS_OPEN,
    ]);
    $this->ticketSink->create($supportAnfrage);
    return new JsonResponse(['support_anfrage_id' => (int) $supportAnfrage->id()], 201);
  }

  /**
   * GET /api/search/live?q=&modus=&maschine_nid= → Top 5 je Dokument.
   */
  public function live(Request $request): JsonResponse {
    $identifier = 'uid:' . $this->currentUser->id();
    if (!$this->flood->isAllowed(self::FLOOD_LIVE, self::FLOOD_LIVE_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return $this->error(429, $this->t('Too many searches. Please wait a minute.'));
    }
    $this->flood->register(self::FLOOD_LIVE, self::FLOOD_WINDOW, $identifier);

    $q = trim((string) $request->query->get('q', ''));
    $modus = $request->query->get('modus') === RetrievalResult::MODE_FULLTEXT ? RetrievalResult::MODE_FULLTEXT : RetrievalResult::MODE_HYBRID;
    $maschineNid = self::optionalId($request->query->get('maschine_nid'));
    if (mb_strlen($q) < 2) {
      return new JsonResponse(['treffer' => [], 'dauer_ms' => 0]);
    }
    if ($maschineNid !== NULL && !$this->machineVisible($maschineNid)) {
      return $this->error(404, $this->t('Machine not found.'));
    }
    $result = $this->retriever->search($q, $this->currentUser, $maschineNid, $modus, self::LIVE_CANDIDATES);
    $best = [];
    foreach ($result->hits as $hit) {
      if (!isset($best[$hit['nid']])) {
        $best[$hit['nid']] = $hit;
      }
      if (count($best) >= self::LIVE_TOP) {
        break;
      }
    }
    $labels = $this->termLabels(array_column($best, 'dokumenttyp_tid'));
    $treffer = [];
    foreach ($best as $hit) {
      $treffer[] = [
        'nid' => (int) $hit['nid'],
        'titel' => (string) $hit['title'],
        'heading_path' => (string) $hit['heading_path'],
        'langcode' => (string) $hit['langcode'],
        'dokumenttyp' => $labels[(int) $hit['dokumenttyp_tid']] ?? '',
        'url' => (string) $hit['url'],
      ];
    }
    return new JsonResponse(['treffer' => $treffer, 'dauer_ms' => $result->dauerMs]);
  }

  /**
   * Eigener Log-Eintrag (uid_hash) oder NULL.
   */
  private function ownLogRow(int $logId): ?array {
    $row = $this->database->select(AnswerService::LOG_TABLE, 'l')
      ->fields('l', ['id', 'uid_hash', 'session_hash', 'frage', 'maschine_nid'])
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row) || !hash_equals((string) $row['uid_hash'], AnswerService::hash((string) $this->currentUser->id()))) {
      return NULL;
    }
    return $row;
  }

  /**
   * TRUE, wenn die Maschine existiert und das Konto sie sehen darf.
   */
  private function machineVisible(int $nid): bool {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node instanceof NodeInterface && $node->bundle() === 'maschine' && $node->access('view', $this->currentUser);
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
   * JSON-Body als Array (leer bei ungültigem JSON).
   */
  private static function body(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Positive Ganzzahl aus JSON/Query oder NULL.
   */
  private static function optionalId(mixed $value): ?int {
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
      return (int) $value;
    }
    return NULL;
  }

  /**
   * Fehlerantwort {fehler}.
   */
  private function error(int $status, \Stringable|string $message): JsonResponse {
    return new JsonResponse(['fehler' => (string) $message], $status);
  }

}
