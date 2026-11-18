<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Drush\Commands;

use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\MigrationRunner;
use Drupal\agency_migrate\Reconciler;
use Drupal\agency_migrate\RollbackService;

/**
 * Drush entry points. Read-only unless an execute flag is passed.
 *
 * Discovered by Drush from this namespace and the drush.command service tag.
 * The class does not extend DrushCommands so PHPStan can analyse the package
 * without Drush installed.
 */
final class AgencyMigrateCommands {

  public function __construct(
    private readonly MigrationRunner $runner,
    private readonly Reconciler $reconciler,
    private readonly AuthorityRegister $authority,
    private readonly RollbackService $rollback,
  ) {}

  /**
   * Copies one source type into its Agency type.
   *
   * @param string $plugin
   *   Copier plugin id.
   * @param array $options
   *   CLI options.
   *
   * @command agency:migrate:run
   * @option execute Perform writes. Without this flag the command is a dry run.
   * @option batch-size Maximum records per batch, from 1 to 500.
   * @usage agency:migrate:run synthetic_item
   * @usage agency:migrate:run synthetic_item --execute --batch-size=50
   */
  public function run(string $plugin, array $options = ['execute' => FALSE, 'batch-size' => 50]): int {
    return $this->emit($this->runner->run(
      $plugin,
      (int) $options['batch-size'],
      $this->flag($options['execute']),
    ));
  }

  /**
   * Resumes an interrupted or failed run.
   *
   * @param string $run_id
   *   Run UUID.
   * @param array $options
   *   CLI options.
   *
   * @command agency:migrate:resume
   * @option execute Perform writes. Without this flag the command is a dry run.
   * @usage agency:migrate:resume 00000000-0000-0000-0000-000000000000 --execute
   */
  public function resume(string $run_id, array $options = ['execute' => FALSE]): int {
    return $this->emit($this->runner->resume($run_id, $this->flag($options['execute'])));
  }

  /**
   * Compares legacy and Agency for one copier plugin.
   *
   * @param string $plugin
   *   Copier plugin id.
   * @param array $options
   *   CLI options.
   *
   * @command agency:migrate:reconcile
   * @option principals Comma-separated principal tokens for access parity.
   * @usage agency:migrate:reconcile synthetic_item --principals=owner-1,other
   */
  public function reconcile(string $plugin, array $options = ['principals' => '']): int {
    $principals = array_values(array_filter(array_map('trim', explode(',', (string) $options['principals']))));
    $report = $this->reconciler->compare($plugin, [], $principals);
    return $this->emit($report->toArray(), $report->passed() ? 0 : 1);
  }

  /**
   * Prints a stored run receipt.
   *
   * @param string $run_id
   *   Run UUID.
   *
   * @command agency:migrate:report
   * @usage agency:migrate:report 00000000-0000-0000-0000-000000000000
   */
  public function report(string $run_id): int {
    $run = $this->runner->loadRun($run_id);
    if ($run === NULL) {
      throw new MigrateRefusal('plugin_missing');
    }
    return $this->emit($this->runner->receipt($run));
  }

  /**
   * Shows authority, or changes it when --set is combined with --execute.
   *
   * @param array $options
   *   CLI options.
   *
   * @command agency:migrate:authority
   * @option domain Domain machine name.
   * @option set Target state. Requires --execute and --reason.
   * @option reason Operator reason recorded with the change.
   * @option execute Perform the authority write.
   * @option register Register a new domain at legacy. Requires --execute.
   * @option rollback Use the agency-to-legacy rollback edge with --set=legacy.
   * @option generation Expected Agency generation for an executing replay.
   * @usage agency:migrate:authority
   * @usage agency:migrate:authority --domain=workforce --set=shadow --reason=rehearsal --execute
   */
  public function authority(array $options = []): int {
    $options += [
      'domain' => '',
      'set' => '',
      'reason' => '',
      'execute' => FALSE,
      'register' => FALSE,
      'rollback' => FALSE,
      'generation' => '',
    ];
    $domain = (string) $options['domain'];
    $set = (string) $options['set'];
    $execute = $this->flag($options['execute']);
    if ($domain === '' && $set === '') {
      return $this->emit(['domains' => $this->authority->status()]);
    }
    if ($set === 'legacy' && $this->flag($options['rollback']) && !$this->flag($options['register'])) {
      $supplied_generation = (string) $options['generation'];
      $generation = $supplied_generation === '' ? NULL : filter_var($supplied_generation, FILTER_VALIDATE_INT);
      if ($generation === FALSE) {
        throw new MigrateRefusal('rollback_stale_generation');
      }
      return $this->emit($this->rollback->rollback($domain, (string) $options['reason'], $execute, $generation));
    }
    if (!$execute) {
      return $this->emit([
        'domain' => $domain,
        'state' => $this->authority->get($domain),
        'status' => 'dry_run',
      ]);
    }
    if ($this->flag($options['register'])) {
      $this->authority->register($domain, (string) $options['reason']);
    }
    elseif ($set !== '') {
      $this->authority->transition($domain, $set, (string) $options['reason'], $this->flag($options['rollback']));
    }
    return $this->emit([
      'domain' => $domain,
      'state' => $this->authority->get($domain),
    ]);
  }

  /**
   * Prints JSON and returns an exit status.
   *
   * @param array<string, mixed> $payload
   *   Machine-readable result.
   * @param int $status
   *   Process exit status.
   */
  private function emit(array $payload, int $status = 0): int {
    print json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    return $status;
  }

  /**
   * Normalizes a CLI flag that Drush may pass as TRUE, 1 or "1".
   */
  private function flag(mixed $value): bool {
    return $value === TRUE || $value === 1 || $value === '1' || $value === 'TRUE';
  }

}
