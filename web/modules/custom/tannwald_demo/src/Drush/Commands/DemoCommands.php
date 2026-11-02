<?php

declare(strict_types=1);

namespace Drupal\tannwald_demo\Drush\Commands;

use Drupal\tannwald_demo\DemoInstaller;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush-Befehle tannwald:demo-install und tannwald:demo-reset.
 *
 * Beide laufen ohne Rückfrage (Skript-Regel) und nur lokal (DDEV) oder in CI.
 */
final class DemoCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'tannwald_demo.installer')]
    protected DemoInstaller $installer,
  ) {
    parent::__construct();
  }

  /**
   * Install the demo data (idempotent).
   *
   * Terms, export rules, migrations, node access rebuild, read confirmation,
   * ingest queues, counts.
   */
  #[CLI\Command(name: 'tannwald:demo-install')]
  #[CLI\Option(name: 'skip-summaries', description: 'Process only tannwald_embed; summary jobs stay queued (fast developer seed).')]
  #[CLI\Option(name: 'skip-queues', description: 'Process no ingest queue at all (chunks follow via cron or tannwald:queue-run).')]
  #[CLI\Usage(name: 'drush tannwald:demo-install', description: 'Seed everything, run embeddings and summaries (real Ollama).')]
  #[CLI\Usage(name: 'drush tannwald:demo-install --skip-summaries', description: 'Seed everything, embeddings only.')]
  public function install(array $options = ['skip-summaries' => FALSE, 'skip-queues' => FALSE]): int {
    if (!$this->allowed()) {
      return self::EXIT_FAILURE;
    }
    $report = $this->installer->install([
      'queues' => !$options['skip-queues'],
      'summaries' => !$options['skip-summaries'],
    ]);
    return $this->printReport($report);
  }

  /**
   * Reset the demo data: roll back, delete demo content, install again.
   */
  #[CLI\Command(name: 'tannwald:demo-reset')]
  #[CLI\Option(name: 'skip-summaries', description: 'Process only tannwald_embed after the reset.')]
  #[CLI\Option(name: 'skip-queues', description: 'Process no ingest queue after the reset.')]
  #[CLI\Usage(name: 'drush tannwald:demo-reset', description: 'Back to the seeded state (audit log and usage stay).')]
  public function reset(array $options = ['skip-summaries' => FALSE, 'skip-queues' => FALSE]): int {
    if (!$this->allowed()) {
      return self::EXIT_FAILURE;
    }
    $report = $this->installer->reset([
      'queues' => !$options['skip-queues'],
      'summaries' => !$options['skip-summaries'],
    ]);
    $deleted = $report['deleted'];
    $rolledBack = $deleted['rolled_back'];
    unset($deleted['rolled_back']);
    $this->io()->table(
      [dt('Deleted'), dt('Count')],
      array_map(fn($key, $value) => [$key, (string) $value], array_keys($deleted), $deleted),
    );
    $this->io()->table(
      [dt('Rolled back'), dt('Rows')],
      array_map(fn($key, $value) => [$key, (string) $value], array_keys($rolledBack), $rolledBack),
    );
    return $this->printReport($report);
  }

  /**
   * Demo-Daten nur lokal (IS_DDEV_PROJECT) oder in CI (CI).
   */
  protected function allowed(): bool {
    if (getenv('IS_DDEV_PROJECT') || getenv('CI')) {
      return TRUE;
    }
    $this->logger()->error(dt('Demo data only in DDEV (IS_DDEV_PROJECT) or CI (CI); nothing changed.'));
    return FALSE;
  }

  /**
   * Gibt den Bericht aus; Exit 1 bei gescheiterten Migrationszeilen.
   *
   * @param array<string, mixed> $report
   *   Bericht von DemoInstaller::install().
   */
  protected function printReport(array $report): int {
    $changes = $report['terms']['created'] + ($report['admin'] === 'set' ? 1 : 0) + ($report['export_rules'] === 'set' ? 1 : 0) + ($report['confirmation'] === 'created' ? 1 : 0);
    $failed = 0;
    $this->io()->writeln(dt('Terms: @created created, @existing existing.', [
      '@created' => $report['terms']['created'],
      '@existing' => $report['terms']['existing'],
    ]));
    $this->io()->writeln($report['admin'] === 'set'
      ? dt('Account admin: role portal_admin and mail admin@tannwald.example set.')
      : dt('Account admin: unchanged.'));
    $this->io()->writeln($report['export_rules'] === 'set'
      ? dt('Export rules: set (direct config write, no audit entry exportregel_geaendert).')
      : dt('Export rules: unchanged.'));

    $rows = [];
    foreach ($report['migrations'] as $id => $migration) {
      $rows[] = [
        $id,
        $migration['source'],
        $migration['created'],
        $migration['updated'],
        $migration['unchanged'],
        $migration['failed'],
        $migration['repaired'],
      ];
      $changes += $migration['created'] + $migration['updated'];
      $failed += $migration['failed'];
    }
    $headers = ['Migration', 'Source', 'Created', 'Updated', 'Unchanged', 'Failed', 'Repaired'];
    $this->io()->table(array_map('dt', $headers), $rows);
    foreach ($report['migrations'] as $id => $migration) {
      foreach ($migration['messages'] as $message) {
        $this->logger()->warning($id . ': ' . $message);
      }
    }
    $images = $report['images'];
    $this->io()->writeln(dt('Images: @files assigned (@targets targets), @assets theme asset.', [
      '@files' => $images['files_assigned'],
      '@targets' => $images['targets'],
      '@assets' => $images['theme_assets'],
    ]));
    foreach ($images['missing'] as $missing) {
      $this->logger()->warning(dt('Image without target: @missing', ['@missing' => $missing]));
    }
    $this->io()->writeln(dt('Node access rebuilt in @seconds s.', ['@seconds' => $report['rebuild_seconds']]));

    $confirmation = [
      'created' => dt('Read confirmation: created (kunde-a, SRV-2026-19, TB200-2021-0417, 03.10.2026; no audit entry).'),
      'unchanged' => dt('Read confirmation: unchanged.'),
      'skipped_module' => dt('Read confirmation: skipped (tannwald_bulletin missing).'),
      'skipped_data' => dt('Read confirmation: skipped (bulletin, machine or account missing).'),
    ];
    $this->io()->writeln($confirmation[$report['confirmation']] ?? $report['confirmation']);

    if ($report['queues'] === []) {
      $this->io()->writeln(dt('Queues: skipped (--skip-queues).'));
    }
    else {
      $rows = [];
      foreach ($report['queues'] as $key => $queue) {
        $rows[] = [
          $key,
          $queue['processed'] ?? '-',
          $queue['ok'] ?? '-',
          $queue['skipped'] ?? '-',
          $queue['failed'] ?? '-',
          $queue['left'] ?? '-',
          $queue['seconds'] ?? '-',
          $queue['status'] ?? '-',
        ];
        if (!empty($queue['failed'])) {
          $this->logger()->warning(dt('@queue: @failed jobs failed (Ollama reachable?); the seed counts anyway, run drush tannwald:reindex and tannwald:queue-run later.', [
            '@queue' => $key,
            '@failed' => $queue['failed'],
          ]));
        }
      }
      $headers = ['Queue', 'Processed', 'OK', 'Skipped', 'Failed', 'Left', 'Seconds', 'Status'];
      $this->io()->table(array_map('dt', $headers), $rows);
    }
    $this->io()->writeln(dt('tannwald_notify: @count jobs deleted (the demo mails come from the live approval of SB-2026-07).', [
      '@count' => $report['notify_cleared'],
    ]));

    $this->io()->table([dt('Count'), dt('Value')], array_map(
      fn($key, $value) => [$key, (string) $value],
      array_keys($report['counts']),
      $report['counts'],
    ));
    $this->io()->writeln(dt('Changes: @changes. Done in @seconds s.', [
      '@changes' => $changes,
      '@seconds' => $report['seconds'],
    ]));
    if ($failed > 0) {
      $this->logger()->error(dt('@failed migration rows failed; see the messages above.', ['@failed' => $failed]));
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

}
