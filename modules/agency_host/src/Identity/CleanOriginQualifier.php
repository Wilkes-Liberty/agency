<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\agency_host\HostSettings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Site\Settings;
use Drupal\Core\Update\UpdateHookRegistry;

/**
 * Initial-origin bootstrap only; never migrates active qualified authority.
 */
final class CleanOriginQualifier {

  public function __construct(
    private readonly Connection $database,
    private readonly Settings $settings,
    private readonly CleanOriginPlan $plan,
    private readonly OriginLedgerInterface $ledger,
    private readonly BindingHistory $history,
    private readonly UpdateHookRegistry $updates,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Consumes one reviewed origin without changing participant activation.
   */
  public function qualify(string $receiptBytes): array {
    $transaction = NULL;
    $record = NULL;
    $committed = FALSE;
    try {
      $approval = HostSettings::get($this->settings, 'origin_approval', []);
      $approved = $approval['plan_sha256'] ?? NULL;
      if (!is_string($approved) || !preg_match('/^[a-f0-9]{64}$/D', $approved)
        || $this->updates->getInstalledVersion('agency_host') !== 10003) {
        throw new \UnexpectedValueException();
      }
      // Also refuses nested transactions, wrong context, expiry and active entry.
      $preflight = $this->plan->report($receiptBytes);
      $existing = $this->ledger->find($preflight['issuer_sha256']);
      if ($existing !== NULL) {
        if ($existing['state'] === 'completed' && $existing['receipt'] === $preflight['receipt_sha256']
          && $existing['plan'] === $approved && $existing['scope'] === $preflight['scope_sha256']) {
          return $this->report('already_completed', $existing);
        }
        throw new \UnexpectedValueException();
      }
      if (!hash_equals($approved, $preflight['plan_sha256'])) {
        throw new \UnexpectedValueException();
      }
      $transaction = $this->fence();
      $captured = $this->plan->captureInFence($receiptBytes);
      if (!hash_equals($approved, $captured['plan_sha256'])) {
        throw new \UnexpectedValueException();
      }
      // A pending guard is not permanent invalidation. Do not migrate an active
      // old epoch, or overwrite even an unqualified row for the selected scope.
      $old = $this->database->select('agency_host_binding_coverage', 'c');
      $old->leftJoin('agency_host_binding_invalid', 'i', 'i.epoch = c.epoch');
      if ($old->fields('c', ['scope'])->condition('c.qualified', 1)->isNull('i.epoch')->execute()->fetchField()
        || $this->database->select('agency_host_binding_coverage', 'c')->fields('c', ['scope'])
          ->condition('scope', $captured['scope_sha256'])->execute()->fetchField()) {
        throw new \UnexpectedValueException();
      }
      $receipt = json_decode($receiptBytes, TRUE, 4, JSON_THROW_ON_ERROR);
      $record = [
        'issuer_key' => $captured['issuer_sha256'],
        'receipt' => $captured['receipt_sha256'],
        'incarnation' => $receipt['realm_incarnation_sha256'],
        'plan' => $approved,
        'scope' => $captured['scope_sha256'],
        'epoch' => bin2hex(random_bytes(32)),
        'guard' => bin2hex(random_bytes(32)),
        'fingerprint' => $captured['host_fingerprint'],
        'state' => 'reserved',
        'created' => $this->time->getCurrentTime(),
        'completed' => 0,
      ];
      $this->ledger->reserve($record);
      // Reservation and its guard are now independently committed. Every later
      // failure retains them; neither issuer nor receipt can be recycled.
      $this->database->insert('agency_host_binding_coverage')->fields([
        'scope' => $record['scope'],
        'epoch' => $record['epoch'],
        'fingerprint' => $record['fingerprint'],
        'provenance' => 'clean-origin-v1:' . $record['receipt'],
        'qualified' => 1,
      ])->execute();
      $this->assertContext($receiptBytes, $record);
      $transaction->commitOrRelease();
      $transaction = NULL;
      $committed = TRUE;

      // Reacquire the full fence after confirmed publication. The independent
      // final transaction touches only ledger/guard tables outside this fence.
      $transaction = $this->fence();
      $this->assertCoverage($record);
      $this->assertContext($receiptBytes, $record);
      $this->ledger->complete($record, $this->time->getCurrentTime());
      $transaction->commitOrRelease();
      $transaction = NULL;
      return $this->report('completed', $record);
    }
    catch (\Throwable) {
      if ($transaction !== NULL) {
        OriginTransaction::rollBack($transaction);
      }
      if ($record !== NULL && $committed) {
        // Lost final acknowledgement may mean success. Read back the primary,
        // never recreate coverage or remove a guard to manufacture a result.
        try {
          $actual = $this->ledger->readCommitted($record['issuer_key']);
          if ($actual !== NULL && $actual['receipt'] === $record['receipt']
            && $actual['epoch'] === $record['epoch'] && $actual['plan'] === $record['plan']) {
            return $this->report($actual['state'] === 'completed' ? 'completed' : 'held', $actual);
          }
        }
        catch (\Throwable) {
          // No reliable readback means outcome unknown, not definitely denied.
        }
        return ['outcome' => 'outcome_unknown', 'activation_performed' => FALSE];
      }
      throw new IdentityDirectoryUnavailable('Clean-origin qualification is unavailable.');
    }
  }

  /**
   * Takes every writer fence before the first MVCC snapshot read.
   */
  private function fence(): Transaction {
    if ($this->database->inTransaction()) {
      throw new IdentityDirectoryUnavailable('Clean-origin fence is unavailable.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->database->prepareStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', [])->execute([]);
      $this->database->query("SET LOCAL statement_timeout = '5s'");
      // NOWAIT also handles the contrib provider-delete path's known lock-order
      // inversion without deadlocking or aborting another writer's transaction.
      foreach ([
        'agency_host_binding_mutex' => 'EXCLUSIVE',
        'authmap' => 'SHARE ROW EXCLUSIVE',
        'config' => 'SHARE',
        'agency_host_binding_history' => 'SHARE ROW EXCLUSIVE',
        'agency_host_binding_association' => 'SHARE ROW EXCLUSIVE',
        'agency_host_binding_coverage' => 'SHARE ROW EXCLUSIVE',
      ] as $table => $mode) {
        $this->database->query('LOCK TABLE {' . $table . '} IN ' . $mode . ' MODE NOWAIT');
      }
      $mutex = $this->database->select('agency_host_binding_mutex', 'm')->fields('m')->execute()->fetchAll();
      if (count($mutex) !== 1 || (int) $mutex[0]->id !== 1) {
        throw new \UnexpectedValueException();
      }
      return $transaction;
    }
    catch (\Throwable $error) {
      OriginTransaction::rollBack($transaction);
      throw $error;
    }
  }

  /**
   * Compares all retained state except the three exact protocol-owned records.
   */
  private function assertContext(string $receipt, array $record): void {
    $plan = $this->plan->captureInFence($receipt, [
      'agency_host_binding_origin' => $record['issuer_key'],
      'agency_host_binding_guard' => $record['guard'],
      'agency_host_binding_coverage' => $record['scope'],
    ]);
    if (!hash_equals($record['plan'], $plan['plan_sha256'])) {
      throw new IdentityDirectoryUnavailable('Clean-origin context changed.');
    }
  }

  /**
   * Does not trust the existence of a completed receipt as current authority.
   */
  private function assertCoverage(array $record): void {
    $coverage = $this->database->select('agency_host_binding_coverage', 'c')->fields('c')
      ->condition('scope', $record['scope'])->execute()->fetchAssoc();
    if (!$coverage || (int) $coverage['qualified'] !== 1
      || $coverage['epoch'] !== $record['epoch'] || $coverage['fingerprint'] !== $record['fingerprint']
      || $coverage['provenance'] !== 'clean-origin-v1:' . $record['receipt']) {
      throw new IdentityDirectoryUnavailable('Clean-origin coverage changed.');
    }
  }

  /**
   * Separates the historical operation receipt from current readiness.
   */
  private function report(string $outcome, array $record): array {
    try {
      $ready = $this->history->qualificationReady();
    }
    catch (\Throwable) {
      $ready = NULL;
    }
    return [
      'outcome' => $outcome,
      'receipt_sha256' => $record['receipt'],
      'plan_sha256' => $record['plan'],
      'epoch_sha256' => $record['epoch'],
      'current_qualification_ready' => $ready,
      'activation_performed' => FALSE,
    ];
  }

}
