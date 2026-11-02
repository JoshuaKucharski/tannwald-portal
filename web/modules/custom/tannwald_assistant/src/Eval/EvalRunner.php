<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Eval;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\tannwald_assistant\AnswerService;
use Drupal\tannwald_assistant\PromptBuilder;
use Drupal\tannwald_assistant\Provider\ProviderResolver;
use Drupal\tannwald_assistant\Result\AnswerResult;
use Drupal\tannwald_assistant\Result\RetrievalResult;
use Drupal\tannwald_assistant\Retriever;
use Drupal\tannwald_assistant\Usage\UsageTracker;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Führt das Eval-Set aus (Pflichtenheft §6.3/§8, Vertrag 4.3).
 *
 * Je Frage als Konto „als“ (oder --as): Retriever::retrieve() (hybrid, ohne
 * Maschine) → hit@5; ohne dry zusätzlich AnswerService::answerWithContext()
 * auf genau diesem Ergebnis (derselbe Weg wie answer() ohne Längen-, Schalter-
 * und Limit-Prüfung; Usage-Kontext eval).
 */
final class EvalRunner {

  public const TOP = 5;

  public function __construct(
    private readonly SourceResolver $sourceResolver,
    private readonly Retriever $retriever,
    private readonly AnswerService $answerService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly RequestStack $requestStack,
    private readonly ProviderResolver $providerResolver,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Führt die Fragen aus.
   *
   * @param \Drupal\tannwald_assistant\Eval\EvalQuestion[] $questions
   *   Fragen (bereits nach --ids/--limit gefiltert).
   * @param bool $dry
   *   TRUE: nur Retrieval.
   * @param string|null $as
   *   Konto für alle Fragen statt „als“.
   * @param string $setPath
   *   Pfad des Sets (für den Kopf).
   *
   * @throws \Drupal\tannwald_assistant\Eval\EvalSetException
   *   Unauflösbare Quelle oder unbekanntes Konto (vor dem Lauf).
   */
  public function run(array $questions, bool $dry, ?string $as = NULL, string $setPath = ''): EvalReport {
    $sources = $this->sourceResolver->resolveAll($questions);
    $accounts = $this->accounts($questions, $as);
    $summary = $this->providerResolver->summary();
    $start = hrtime(TRUE);
    $request = $this->requestStack->getCurrentRequest();
    $previousKontext = $request->attributes->get(UsageTracker::KONTEXT_ATTRIBUTE);
    $request->attributes->set(UsageTracker::KONTEXT_ATTRIBUTE, 'eval');
    $session = 'eval-' . $this->time->getRequestTime();
    $rows = [];
    try {
      foreach ($questions as $question) {
        $account = $accounts[$as ?? $question->als];
        $this->accountSwitcher->switchTo($account);
        try {
          $rows[] = $this->evaluate($question, $sources[$question->id], $account, $dry, $session);
        }
        finally {
          $this->accountSwitcher->switchBack();
        }
      }
    }
    finally {
      if ($previousKontext === NULL) {
        $request->attributes->remove(UsageTracker::KONTEXT_ATTRIBUTE);
      }
      else {
        $request->attributes->set(UsageTracker::KONTEXT_ATTRIBUTE, $previousKontext);
      }
    }
    return new EvalReport($rows, $dry, [
      'datum' => date('Y-m-d H:i', $this->time->getRequestTime()),
      'provider' => $summary['provider'],
      'chat_model' => $summary['chat_model'],
      'embedding_model' => $summary['embedding_model'],
      'set' => $setPath,
      'as' => $as,
      'dauer_s' => round((hrtime(TRUE) - $start) / 1e9, 1),
    ]);
  }

  /**
   * Bewertet eine Frage.
   *
   * @param \Drupal\tannwald_assistant\Eval\EvalQuestion $question
   *   Frage.
   * @param array<int, array{nid: int, vid: int, version: ?string, key: string, kapitel: string, dokument: string}> $sources
   *   Aufgelöste erwartete Quellen.
   * @param \Drupal\user\UserInterface $account
   *   Konto.
   * @param bool $dry
   *   Nur Retrieval.
   * @param string $session
   *   Session-Kennung des Laufs (nur gehasht im Log).
   */
  private function evaluate(EvalQuestion $question, array $sources, UserInterface $account, bool $dry, string $session): array {
    $result = $this->retriever->retrieve($question->frage, $account, NULL, RetrievalResult::MODE_HYBRID);
    $top = array_slice($result->hits, 0, self::TOP);
    $hit = $sources === [] ? NULL : $this->anyMatch($sources, $top, TRUE);
    $row = [
      'id' => $question->id,
      'frage' => $question->frage,
      'als' => $account->getAccountName(),
      'sprache' => $question->sprache,
      'erwartet' => $question->erwartet,
      'status' => $dry ? ($result->isEmpty() ? 'leer' : 'treffer') : '',
      'hit' => $hit,
      'zitat' => NULL,
      'ablehnung' => NULL,
      'safety' => NULL,
      'dauer_ms' => $result->dauerMs,
      'tokens' => ['prompt' => 0, 'completion' => 0],
      'fehler' => FALSE,
      'erwartete' => array_map(static fn (array $s): string => $s['dokument'] . ($s['version'] !== NULL ? ' ' . $s['version'] : '') . ' · ' . $s['kapitel'], $sources),
      'erhalten' => array_map(static fn (array $h): string => sprintf('[%d] %s v%s · %s', $h['n'], $h['title'], $h['version'], PromptBuilder::lastHeading((string) $h['heading_path'])), $top),
      'bemerkung' => $hit === FALSE ? 'erwartete Quelle nicht unter den ersten 5 Treffern' : '',
    ];
    if ($dry) {
      return $row;
    }

    $answer = $this->answerService->answerWithContext($question->frage, $result, $account, $session);
    $row['status'] = $answer->status;
    $row['dauer_ms'] += $answer->dauerMs;
    $row['tokens'] = $answer->tokens;
    $row['erhalten'] = array_map(static fn (array $q): string => sprintf('[%d] %s v%s · %s', $q['n'], $q['titel'], $q['version'], PromptBuilder::lastHeading((string) $q['heading_path'])), $answer->quellen);
    // Provider nicht erreichbar endet in „limit“ (4.2) — im Eval ein Fehler.
    $row['fehler'] = $answer->status === AnswerResult::STATUS_LIMIT;
    $cited = $answer->quellen;
    $notes = [];
    if ($question->erwartet === 'antwort') {
      $row['zitat'] = $answer->status === AnswerResult::STATUS_ANSWERED && $cited !== [] && $this->allMatch($sources, $cited);
      $row['ablehnung'] = $answer->status !== AnswerResult::STATUS_REFUSED;
      if (!$row['zitat']) {
        $notes[] = $answer->status === AnswerResult::STATUS_ANSWERED ? 'Zitat zeigt nicht auf eine erwartete Quelle' : 'keine belegte Antwort (' . $answer->status . ')';
      }
    }
    elseif ($question->erwartet === 'ablehnung') {
      $row['ablehnung'] = $answer->status === AnswerResult::STATUS_REFUSED;
      if (!$row['ablehnung']) {
        $notes[] = 'erwartet Ablehnung, erhalten ' . $answer->status;
      }
    }
    else {
      $row['safety'] = $answer->status === AnswerResult::STATUS_SAFETY && $this->anyMatch($sources, $cited, FALSE);
      if (!$row['safety']) {
        $notes[] = $answer->status === AnswerResult::STATUS_SAFETY ? 'Sicherheitsvorlage ohne das erwartete Kapitel' : 'erwartet safety, erhalten ' . $answer->status;
      }
    }
    if ($row['bemerkung'] !== '') {
      $notes[] = $row['bemerkung'];
    }
    $row['bemerkung'] = implode('; ', $notes);
    return $row;
  }

  /**
   * TRUE, wenn ein Treffer/Zitat auf eine erwartete Quelle zeigt.
   *
   * @param array $sources
   *   Erwartete Quellen (aufgelöst).
   * @param array $items
   *   Treffer (mit vid) oder Quellen einer Antwort (mit version).
   * @param bool $byRevision
   *   TRUE: vid vergleichen (Treffer); FALSE: version, wenn erwartet (Zitate).
   */
  private function anyMatch(array $sources, array $items, bool $byRevision): bool {
    foreach ($items as $item) {
      if ($this->matchesAny($sources, $item, $byRevision)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * TRUE, wenn jedes Zitat auf eine erwartete Quelle zeigt.
   */
  private function allMatch(array $sources, array $cited): bool {
    foreach ($cited as $item) {
      if (!$this->matchesAny($sources, $item, FALSE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Vergleich nid + Kapitelschlüssel (+ Revision bzw. Version).
   */
  private function matchesAny(array $sources, array $item, bool $byRevision): bool {
    $key = SourceResolver::chapterKey(PromptBuilder::lastHeading((string) $item['heading_path']));
    foreach ($sources as $source) {
      if ((int) $item['nid'] !== $source['nid'] || $key !== $source['key']) {
        continue;
      }
      if ($byRevision && (int) $item['vid'] !== $source['vid']) {
        continue;
      }
      if (!$byRevision && $source['version'] !== NULL && (string) ($item['version'] ?? '') !== $source['version']) {
        continue;
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Lädt die Konten aller Fragen (oder --as) vorab.
   *
   * @return array<string, \Drupal\user\UserInterface>
   *   Name => Konto.
   */
  private function accounts(array $questions, ?string $as): array {
    $names = $as !== NULL ? [$as] : array_values(array_unique(array_map(static fn (EvalQuestion $q): string => $q->als, $questions)));
    $accounts = [];
    $errors = [];
    foreach ($names as $name) {
      $found = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name]);
      $account = reset($found);
      if ($account instanceof UserInterface && $account->isActive()) {
        $accounts[$name] = $account;
      }
      else {
        $errors['als:' . $name] = 'account not found or blocked';
      }
    }
    if ($errors !== []) {
      throw new EvalSetException($errors);
    }
    return $accounts;
  }

}
