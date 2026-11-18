<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Qualifies journal replay before handing authority back to legacy.
 */
final class RollbackService {

  public function __construct(
    private readonly CopierManager $copiers,
    private readonly RollbackReplayStore $writes,
    private readonly AuthorityRegister $authority,
    private readonly AccountProxyInterface $currentUser,
    private readonly Reconciler $reconciler,
    private readonly QualifiedRollbackPlanner $planner,
    private readonly Connection $database,
  ) {}

  /**
   * Previews or atomically executes a qualified domain handover.
   *
   * A nonempty execution requires the expected Agency generation. Supply that
   * same generation and reason to read its receipt after an uncertain result.
   *
   * @return array<string, mixed>
   *   Preview or a committed handover receipt with replayed count.
   */
  public function rollback(string $domain, string $reason, bool $execute = FALSE, ?int $expected_generation = NULL): array {
    if (!$this->currentUser->hasPermission('run agency migrate')) {
      throw new MigrateRefusal('forbidden');
    }
    $this->authority->assertPermission('administer agency migrate authority');
    if ($execute && $this->database->inTransaction()) {
      throw new MigrateRefusal('writer_transaction_owned');
    }
    if ($expected_generation !== NULL && ($expected_generation < 1 || $expected_generation === PHP_INT_MAX)) {
      throw new MigrateRefusal('rollback_stale_generation');
    }
    $state = $this->authority->status()[$domain] ?? NULL;
    if ($expected_generation !== NULL && $execute && ($state['state'] ?? NULL) === 'legacy') {
      return $this->existingReceipt($domain, $reason, $expected_generation);
    }
    $this->authority->assertTransition($domain, 'legacy', $reason, TRUE);
    $generation = $expected_generation ?? $state['version'] ?? NULL;
    if ($generation === NULL || $generation !== ($state['version'] ?? NULL)) {
      throw new MigrateRefusal('rollback_stale_generation');
    }
    if (!$execute) {
      [$operations] = $this->preflight($domain, $generation);
      $preview = ['domain' => $domain, 'pending' => count($operations), 'execute' => FALSE, 'status' => 'dry_run'];
      return $operations === [] && $expected_generation === NULL ? $preview : $preview + ['generation' => $generation];
    }

    $effects = [];
    $targets = [];
    $ready_to_commit = FALSE;
    try {
      return $this->authority->withTransitionFence($domain, 'legacy', $reason, TRUE, function () use ($domain, $reason, $generation, $expected_generation, &$effects, &$targets, &$ready_to_commit): array {
        $this->authority->assertLockedRollback($domain, $generation);
        [$operations, $copiers, $targets, $changes] = $this->preflight($domain, $generation);
        if ($operations !== []) {
          if ($expected_generation === NULL) {
            throw new MigrateRefusal('rollback_generation_required');
          }
          $effects = $operations;
          $this->planner->replay($targets, $operations);
          $this->planner->verifyReplayed($copiers, $changes);
        }
        $receipt = $this->writes->seal($domain, $generation, $reason, $operations, $changes);
        $this->authority->completeVerifiedRollback($domain, $reason, $generation);
        $ready_to_commit = TRUE;
        return $this->publicReceipt($receipt, $expected_generation !== NULL);
      });
    }
    catch (\Throwable $exception) {
      try {
        $this->planner->clearLegacyCaches($targets, $effects);
      }
      catch (\Throwable) {
        // Preserve the sanitized failure or uncertain-commit result below.
      }
      if ($ready_to_commit) {
        throw new MigrateRefusal('rollback_commit_uncertain');
      }
      // A second executor can wait behind the first and find legacy at recheck.
      if ($expected_generation !== NULL && $effects === [] && $exception instanceof MigrateRefusal
        && $exception->reason === 'invalid_transition' && $this->authority->get($domain) === 'legacy') {
        return $this->existingReceipt($domain, $reason, $expected_generation);
      }
      throw $exception instanceof MigrateRefusal ? $exception : new MigrateRefusal('rollback_failed');
    }
  }

  /**
   * Reads the entire domain and journal before allowing any legacy effects.
   */
  private function preflight(string $domain, int $generation): array {
    $copiers = $this->copiers->copiersForDomain($domain);
    $targets = [];
    foreach ($copiers as $copier) {
      $targets[$copier->targetEntityType()] = $copier;
    }
    try {
      $operations = $this->writes->pending($domain, $generation, $targets);
      if ($operations === []) {
        foreach (array_keys($copiers) as $id) {
          if (!$this->reconciler->compare($id)->fullPassed()) {
            throw new MigrateRefusal('rollback_unqualified');
          }
        }
        $changes = [];
      }
      else {
        $changes = $this->planner->preflight($copiers, $operations);
      }
    }
    catch (MigrateRefusal $exception) {
      throw in_array($exception->reason, ['rollback_unqualified', 'rollback_conflict'], TRUE)
        ? $exception : new MigrateRefusal('rollback_unqualified');
    }
    catch (\Throwable) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return [$operations, $copiers, $targets, $changes];
  }

  /**
   * Recovers only the matching receipt at its immediately resulting authority.
   */
  private function existingReceipt(string $domain, string $reason, int $generation): array {
    $transaction = $this->database->startTransaction();
    try {
      $state = $this->authority->lockOperationAuthority($this->database, $domain);
      if ($state !== ['state' => 'legacy', 'version' => $generation + 1]) {
        throw new MigrateRefusal('rollback_stale_generation');
      }
      $receipt = $this->writes->receipt($domain, $generation);
      if ($receipt === NULL || !hash_equals($receipt['reason_hash'], hash('sha256', trim($reason)))) {
        throw new MigrateRefusal('rollback_stale_generation');
      }
      TransactionBoundary::commit($transaction);
      return $this->publicReceipt($receipt, TRUE);
    }
    catch (\Throwable $exception) {
      if ($this->database->inTransaction()) {
        $transaction?->rollBack();
      }
      throw $exception instanceof MigrateRefusal ? $exception : new MigrateRefusal('rollback_failed');
    }
  }

  /**
   * Exposes the resulting authority and count without private replay values.
   */
  private function publicReceipt(array $receipt, bool $include_generation): array {
    $public = ['domain' => $receipt['domain'], 'replayed' => $receipt['replayed'], 'authority' => 'legacy', 'status' => 'completed'];
    return $include_generation ? $public + ['generation' => $receipt['generation'] + 1] : $public;
  }

}
