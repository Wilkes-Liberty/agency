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

  /**
   * The attempt accepted by the authority coordinator in this request.
   */
  private ?RollbackAttempt $activeAttempt = NULL;

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
    try {
      return $this->rollbackCurrent($domain, $reason, $execute, $expected_generation);
    }
    catch (MigrateRefusal $exception) {
      throw match ($exception->reason) {
        'forbidden', 'writer_transaction_owned', 'authority_storage_unavailable',
        'rollback_unqualified', 'rollback_conflict', 'rollback_ambiguous', 'rollback_failed',
        'rollback_stale_generation', 'rollback_generation_required', 'rollback_commit_uncertain',
        'invalid_transition', 'writer_fence_unavailable' => new MigrateRefusal($exception->reason),
        'history_conflict', 'history_drift' => new MigrateRefusal('rollback_conflict'),
        default => new MigrateRefusal('rollback_failed'),
      };
    }
    catch (\Throwable) {
      throw new MigrateRefusal('rollback_failed');
    }
  }

  /**
   * Dispatches only after establishing permissions and expected authority.
   */
  private function rollbackCurrent(string $domain, string $reason, bool $execute, ?int $expected_generation): array {
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
    if (!$execute || $state === NULL) {
      $this->authority->assertTransition($domain, 'legacy', $reason, TRUE);
    }
    $generation = $expected_generation ?? $state['version'] ?? NULL;
    if ($generation === NULL || $generation !== ($state['version'] ?? NULL)) {
      throw new MigrateRefusal('rollback_stale_generation');
    }
    if (!$execute) {
      [$operations] = $this->preflight($domain, $generation);
      $preview = ['domain' => $domain, 'pending' => count($operations), 'execute' => FALSE, 'status' => 'dry_run'];
      return $operations === [] && $expected_generation === NULL ? $preview : $preview + ['generation' => $generation];
    }

    $attempt = new RollbackAttempt();
    $this->activeAttempt = $attempt;
    try {
      $receipt = $this->authority->runQualifiedRollback($this, $domain, $reason, $generation, $expected_generation !== NULL, $attempt);
      if ($this->database->inTransaction()) {
        throw new MigrateRefusal('rollback_commit_uncertain');
      }
      return $this->publicReceipt($receipt, $expected_generation !== NULL);
    }
    catch (\Throwable $exception) {
      try {
        $this->planner->clearLegacyCaches($attempt->targets, $attempt->effects);
      }
      catch (\Throwable) {
        // Preserve the sanitized failure or uncertain-commit result below.
      }
      if ($attempt->readyToCommit) {
        throw new MigrateRefusal('rollback_commit_uncertain');
      }
      // A second executor can wait behind the first and find legacy at recheck.
      if ($expected_generation !== NULL && $attempt->effects === [] && $exception instanceof MigrateRefusal
        && $exception->reason === 'invalid_transition' && $this->authority->get($domain) === 'legacy') {
        return $this->existingReceipt($domain, $reason, $expected_generation);
      }
      throw $exception;
    }
    finally {
      $this->activeAttempt = NULL;
    }
  }

  /**
   * Preflights and applies only the in-flight fenced rollback attempt.
   *
   * @internal
   */
  public function applyUnderFence(string $domain, string $reason, int $generation, bool $expected, RollbackAttempt $attempt): array {
    if ($this->activeAttempt !== $attempt) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->authority->assertLockedRollback($domain, $generation);
    [$operations, $copiers, $targets, $changes, $effective] = $this->preflight($domain, $generation);
    if ($operations !== []) {
      if (!$expected) {
        throw new MigrateRefusal('rollback_generation_required');
      }
      $attempt->targets = $targets;
      $attempt->effects = $operations;
      $this->planner->replay($targets, $operations);
      $this->planner->verifyReplayed($copiers, $changes, $effective);
    }
    return $this->writes->seal($domain, $generation, $reason, $operations, $changes);
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
      $effective = $this->writes->effectiveDomain($domain);
      if (array_diff_key($effective, $copiers) !== []) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      if ($operations === []) {
        foreach (array_keys($copiers) as $id) {
          if (!$this->reconciler->compare($id)->fullPassed()) {
            throw new MigrateRefusal('rollback_unqualified');
          }
        }
        $changes = [];
      }
      else {
        $changes = $this->planner->preflight($copiers, $operations, $effective);
      }
    }
    catch (MigrateRefusal $exception) {
      throw in_array($exception->reason, ['rollback_unqualified', 'rollback_conflict'], TRUE)
        ? $exception : new MigrateRefusal('rollback_unqualified');
    }
    catch (\Throwable) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return [$operations, $copiers, $targets, $changes, $effective];
  }

  /**
   * Recovers only the matching receipt at its immediately resulting authority.
   */
  private function existingReceipt(string $domain, string $reason, int $generation): array {
    try {
      // A successful COMMIT with a failing end callback may leave the older
      // core transaction manager unusable for a new root on this request.
      $receipt = $this->writes->committedReceipt($domain, $generation, $reason);
    }
    catch (\Throwable) {
      throw new MigrateRefusal('rollback_failed');
    }
    if ($receipt === NULL) {
      throw new MigrateRefusal('rollback_stale_generation');
    }
    return $this->publicReceipt($receipt, TRUE);
  }

  /**
   * Exposes the resulting authority and count without private replay values.
   */
  private function publicReceipt(array $receipt, bool $include_generation): array {
    $public = [
      'domain' => $receipt['domain'],
      'replayed' => $receipt['replayed'],
      'authority' => 'legacy',
      'status' => 'completed',
    ];
    return $include_generation ? $public + ['generation' => $receipt['generation'] + 1] : $public;
  }

}
