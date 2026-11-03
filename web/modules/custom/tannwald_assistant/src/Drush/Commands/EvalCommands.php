<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\tannwald_assistant\Eval\EvalQuestion;
use Drupal\tannwald_assistant\Eval\EvalReport;
use Drupal\tannwald_assistant\Eval\EvalRunner;
use Drupal\tannwald_assistant\Eval\EvalSetException;
use Drupal\tannwald_assistant\Provider\ProviderResolver;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush tannwald:eval (Pflichtenheft §6.3, §8; Vertrag 4.3 a).
 */
final class EvalCommands extends DrushCommands {

  use AutowireTrait;

  public const DEFAULT_SET = 'docs/evals/fragen.yaml';

  public const RESULT_DIR = 'docs/evals';

  public const EXIT_SET_ERROR = 2;

  public function __construct(
    #[Autowire(service: 'tannwald_assistant.eval_runner')]
    private readonly EvalRunner $runner,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
    #[Autowire(service: 'tannwald_assistant.provider_resolver')]
    private readonly ProviderResolver $resolver,
  ) {
    parent::__construct();
  }

  /**
   * Führt das Eval-Set aus und schreibt die Ergebnisdatei in docs/evals.
   */
  #[CLI\Command(name: 'tannwald:eval')]
  #[CLI\Option(name: 'set', description: 'Eval set (YAML), relative to the repository root. Default docs/evals/fragen.yaml.')]
  #[CLI\Option(name: 'as', description: 'Account name for all questions instead of "als".')]
  #[CLI\Option(name: 'limit', description: 'Only the first n questions (writes a _teil file).')]
  #[CLI\Option(name: 'dry', description: 'Retrieval only (hit@5), no LLM.')]
  #[CLI\Option(name: 'ids', description: 'Comma-separated question ids, e.g. q-001,q-002 (writes a _teil file).')]
  #[CLI\Option(name: 'out', description: 'Result file path instead of docs/evals/ergebnisse-<YYYY-MM-DD_HHMM>.md.')]
  #[CLI\Option(name: 'chat-provider', description: 'Chat provider for this run only (ollama|dashscope); the stored configuration stays unchanged.')]
  #[CLI\Option(name: 'chat-model', description: 'Chat model (raw name) for this run only, with --chat-provider.')]
  #[CLI\Usage(name: 'drush tannwald:eval --dry', description: 'hit@5 over all 60 questions without LLM.')]
  #[CLI\Usage(name: 'drush tannwald:eval --limit=5', description: 'First five questions with LLM answers.')]
  #[CLI\Usage(name: 'drush tannwald:eval --chat-provider=dashscope', description: 'All questions with chat over DashScope, embeddings as configured.')]
  public function evaluate(
    array $options = [
      'set' => self::DEFAULT_SET,
      'as' => self::REQ,
      'limit' => self::REQ,
      'dry' => FALSE,
      'ids' => self::REQ,
      'out' => self::REQ,
      'chat-provider' => self::REQ,
      'chat-model' => self::REQ,
    ],
  ): int {
    $root = dirname(DRUPAL_ROOT);
    $set = (string) ($options['set'] ?: self::DEFAULT_SET);
    $setPath = str_starts_with($set, '/') ? $set : $root . '/' . $set;
    try {
      if ($chatProvider = (string) ($options['chat-provider'] ?? '')) {
        // Nur dieser Prozess; die geteilte Konfiguration bleibt unverändert.
        $this->resolver->overrideForProcess('chat', $chatProvider, ($options['chat-model'] ?? NULL) ?: NULL);
      }
      elseif (!empty($options['chat-model'])) {
        throw new EvalSetException(['chat-model' => 'requires --chat-provider']);
      }
      $questions = EvalQuestion::loadSet($setPath);
      [$questions, $selection] = self::select($questions, $options['ids'] ?? NULL, $options['limit'] ?? NULL);
      $report = $this->runner->run($questions, !empty($options['dry']), ($options['as'] ?? NULL) ?: NULL, $set);
    }
    catch (EvalSetException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_SET_ERROR;
    }
    $report->meta['auswahl'] = $selection;

    $this->io()->table(['Kennzahl', 'Wert', 'Basis', 'Ziel', 'erreicht'], $report->metricRows());
    $this->io()->table(EvalReport::QUESTION_HEADER, $report->questionRows());

    $out = ($options['out'] ?? NULL) ?: $root . '/' . self::RESULT_DIR . '/' . self::fileName($this->time->getRequestTime(), !empty($options['dry']), $selection !== '');
    if (!is_dir(dirname($out))) {
      mkdir(dirname($out), 0775, TRUE);
    }
    file_put_contents($out, $report->toMarkdown());
    $this->io()->writeln('Ergebnisdatei: ' . (str_starts_with($out, $root . '/') ? substr($out, strlen($root) + 1) : $out));
    $this->io()->writeln('Exit-Code: ' . $report->exitCode());
    return $report->exitCode();
  }

  /**
   * Name der Ergebnisdatei: ergebnisse-<YYYY-MM-DD_HHMM>[_dry][_teil].md.
   *
   * Ohne Suffix ist es ein voller LLM-Lauf (der Lauf des Betreibers).
   */
  public static function fileName(int $time, bool $dry, bool $partial): string {
    return 'ergebnisse-' . date('Y-m-d_Hi', $time) . ($dry ? '_dry' : '') . ($partial ? '_teil' : '') . '.md';
  }

  /**
   * Filtert nach --ids und --limit.
   *
   * @param \Drupal\tannwald_assistant\Eval\EvalQuestion[] $questions
   *   Alle Fragen.
   * @param mixed $ids
   *   Kommaliste oder NULL.
   * @param mixed $limit
   *   Anzahl oder NULL.
   *
   * @return array{0: \Drupal\tannwald_assistant\Eval\EvalQuestion[], 1: string}
   *   Auswahl und Beschreibung ('' = alle).
   */
  public static function select(array $questions, mixed $ids, mixed $limit): array {
    $selection = [];
    if (is_string($ids) && trim($ids) !== '') {
      $wanted = array_values(array_filter(array_map('trim', explode(',', $ids))));
      $known = array_map(static fn (EvalQuestion $q): string => $q->id, $questions);
      $unknown = array_diff($wanted, $known);
      if ($unknown !== []) {
        throw new EvalSetException(array_fill_keys($unknown, 'unknown id'));
      }
      $questions = array_values(array_filter($questions, static fn (EvalQuestion $q): bool => in_array($q->id, $wanted, TRUE)));
      $selection[] = '--ids=' . implode(',', $wanted);
    }
    if ($limit !== NULL && $limit !== '' && $limit !== FALSE) {
      if (!ctype_digit((string) $limit) || (int) $limit < 1) {
        throw new EvalSetException(['limit' => 'expected a positive number']);
      }
      $questions = array_slice($questions, 0, (int) $limit);
      $selection[] = '--limit=' . (int) $limit;
    }
    return [$questions, implode(' ', $selection)];
  }

}
