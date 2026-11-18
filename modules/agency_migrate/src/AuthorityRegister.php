<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Operational per-domain authority, serialized independently of configuration.
 */
final class AuthorityRegister {

  private const STATES = ['legacy', 'shadow', 'agency', 'removed'];

  /**
   * Domains currently inside this instance's fenced callback.
   *
   * @var array<string, bool>
   */
  private array $fencedDomains = [];

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly MigrateOutbox $outbox,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly HostWriterFenceInterface $writerFence,
    private readonly RollbackDispositionRegistry $dispositions = new RollbackDispositionRegistry(),
  ) {}

  /**
   * Recorded state for a domain, or NULL when unregistered.
   */
  public function get(string $domain): ?string {
    return $this->row($domain)['state'] ?? NULL;
  }

  /**
   * Locks a legacy writer's current generation until its root transaction ends.
   *
   * The caller must authorize its business operation and supply the exact
   * connection used for every mutation. This method neither starts nor commits
   * that transaction, changes authority, or certifies all host writer coverage.
   * It permits anonymous workflows without granting authority administration.
   *
   * @return int
   *   The locked legacy/shadow generation, valid only in that transaction.
   */
  public function lockForLegacyWrite(Connection $connection, string $domain): int {
    $this->assertDomain($domain);
    if ($connection !== $this->database) {
      throw new MigrateRefusal('writer_connection_mismatch');
    }
    if (!$connection->inTransaction()) {
      throw new MigrateRefusal('writer_transaction_required');
    }
    $row = $this->row($domain, TRUE);
    if ($row === NULL || !in_array($row['state'], ['legacy', 'shadow'], TRUE)) {
      throw new MigrateRefusal('legacy_writer_refused');
    }
    return $row['version'];
  }

  /**
   * Locks current authority while the operation writer checks for a receipt.
   *
   * @return array{state: string, version: int}|null
   *   The locked state and generation, or NULL for an unregistered domain.
   */
  public function lockOperationAuthority(Connection $connection, string $domain): ?array {
    $this->assertDomain($domain);
    if ($connection !== $this->database) {
      throw new MigrateRefusal('writer_connection_mismatch');
    }
    if (!$connection->inTransaction()) {
      throw new MigrateRefusal('writer_transaction_required');
    }
    $row = $this->row($domain, TRUE);
    return $row === NULL ? NULL : ['state' => $row['state'], 'version' => $row['version']];
  }

  /**
   * All recorded states and versions; this does not certify host coverage.
   *
   * @return array<string, array<string, mixed>>
   *   Domain id keyed to operational state and transition metadata.
   */
  public function status(): array {
    $this->assertStorage();
    $rows = $this->database->select('agency_migrate_authority_state', 'a')->fields('a')->orderBy('domain')->execute();
    $domains = [];
    while ($row = $rows->fetchAssoc()) {
      $domain = $row['domain'];
      unset($row['domain']);
      $validated = $this->validatedRow($row);
      unset($validated['disposition_provider'], $validated['disposition_required_after']);
      $domains[$domain] = $validated;
    }
    return $domains;
  }

  /**
   * Registers one domain at legacy; concurrent duplicates refuse.
   */
  public function register(string $domain, string $reason): void {
    $this->assertDomain($domain);
    $this->assertReason($reason);
    $this->assertPermission('administer agency migrate authority');
    $this->assertStorage();
    $transaction = $this->database->startTransaction();
    try {
      $values = $this->metadata('legacy', 1, $reason);
      try {
        $this->database->insert('agency_migrate_authority_state')->fields(['domain' => $domain] + $values)->execute();
      }
      catch (IntegrityConstraintViolationException) {
        throw new MigrateRefusal('invalid_transition');
      }
      $this->recordEvent($domain, 'none', $values, FALSE);
      TransactionBoundary::commit($transaction);
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      throw $exception;
    }
  }

  /**
   * Durably enrolls a trusted participant before the domain enters Agency.
   */
  public function requireDisposition(string $domain, string $provider_id): void {
    $this->assertPermission('administer agency migrate authority');
    $this->assertDomain($domain);
    if (!RollbackDispositionRegistry::validProviderId($provider_id)) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    if ($this->database->inTransaction()) {
      throw new MigrateRefusal('writer_transaction_owned');
    }
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->row($domain, TRUE);
      if ($row === NULL || !in_array($row['state'], ['legacy', 'shadow'], TRUE)
        || ($row['disposition_provider'] !== NULL && $row['disposition_provider'] !== $provider_id)) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      if ($row['disposition_provider'] === NULL) {
        $this->dispositions->resolve($domain, $provider_id);
        $enrollment = ['disposition_provider' => $provider_id, 'disposition_required_after' => $row['version']];
        $changed = $this->database->update('agency_migrate_authority_state')->fields($enrollment)
          ->condition('domain', $domain)->condition('version', $row['version'])
          ->condition('state', $row['state'])->isNull('disposition_provider')->isNull('disposition_required_after')->execute();
        if ($changed !== 1 || $this->row($domain, TRUE) !== array_replace($row, $enrollment)) {
          throw new MigrateRefusal('rollback_unqualified');
        }
      }
      TransactionBoundary::commit($transaction);
    }
    catch (\Throwable $exception) {
      if ($this->database->inTransaction()) {
        $transaction->rollBack();
      }
      throw $exception;
    }
  }

  /**
   * Loads the checked participant for this forward cutover, if required.
   */
  private function bindDisposition(string $domain, int $generation, ?string $required): void {
    if ($required === NULL && $this->dispositions->declaration($domain) === NULL) {
      return;
    }
    if ($required === NULL) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->assertLockedCutover($domain, $generation);
    $provider = $this->dispositions->resolve($domain, $required);
    try {
      $provider->qualifyCutover($this->database, $domain, $generation);
    }
    catch (\Throwable) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->assertLockedCutover($domain, $generation);
    $this->database->insert('agency_migrate_disposition_binding')->fields([
      'domain' => $domain,
      'generation' => $generation,
      'provider' => $required,
    ])->execute();
    if ($this->dispositionBinding($domain, $generation) !== $required) {
      throw new MigrateRefusal('rollback_unqualified');
    }
  }

  /**
   * Resolves only the active generation's durable rollback binding.
   *
   * @return array{id: string, provider: \Drupal\agency_migrate\RollbackDispositionInterface}|null
   *   The bound participant, if required.
   *
   * @internal
   */
  public function rollbackDisposition(string $domain, int $generation): ?array {
    $row = $this->row($domain);
    if ($row === NULL || $row['state'] !== 'agency' || $row['version'] !== $generation) {
      throw new MigrateRefusal('rollback_stale_generation');
    }
    $binding = $this->dispositionBinding($domain, $generation);
    if ($binding === NULL && $this->dispositions->declaration($domain) !== NULL) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $provider = $this->resolveDisposition($domain, $generation, $row['disposition_provider'], $row['disposition_required_after'], $binding);
    return $provider === NULL || $binding === NULL ? NULL : ['id' => $binding, 'provider' => $provider];
  }

  /**
   * Resolves persisted enrollment and binding without inferring historic rows.
   *
   * @internal
   */
  public function resolveDisposition(string $domain, int $generation, ?string $required, ?int $required_after, ?string $binding): ?RollbackDispositionInterface {
    if (($required === NULL) !== ($required_after === NULL)
      || ($required !== NULL && !RollbackDispositionRegistry::validProviderId($required))
      || ($required_after !== NULL && ($required_after < 1 || $required_after > $generation + 1))) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $expected = $required_after !== NULL && $generation > $required_after ? $required : NULL;
    if ($binding !== $expected) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $binding === NULL ? NULL : $this->dispositions->resolve($domain, $binding);
  }

  /**
   * Reads one immutable binding by its exact scope.
   */
  private function dispositionBinding(string $domain, int $generation): ?string {
    $binding = $this->database->select('agency_migrate_disposition_binding', 'd')->fields('d', ['provider'])
      ->condition('domain', $domain)->condition('generation', $generation)->execute()->fetchField();
    return $binding === FALSE ? NULL : $binding;
  }

  /**
   * Advances one step in the normal authority lifecycle.
   */
  public function transition(string $domain, string $to, string $reason, bool $rollback = FALSE): void {
    if ($rollback) {
      $this->assertTransition($domain, $to, $reason, TRUE);
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->withTransitionFence($domain, $to, $reason, FALSE, function () use ($domain, $to, $reason): void {
      $row = $this->row($domain, TRUE);
      if ($row === NULL) {
        throw new MigrateRefusal('invalid_transition');
      }
      if ($to === 'agency') {
        $this->bindDisposition($domain, $row['version'] + 1, $row['disposition_provider']);
      }
      $this->advanceLocked($domain, $row['state'], $to, $row['version'], $reason, FALSE);
    });
  }

  /**
   * Asserts the existing fenced cutover still owns the shadow generation.
   */
  public function assertLockedCutover(string $domain, int $prospectiveGeneration): void {
    if (!isset($this->fencedDomains[$domain]) || !$this->database->inTransaction() || $prospectiveGeneration < 2) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $row = $this->lockOperationAuthority($this->database, $domain);
    if ($row !== ['state' => 'shadow', 'version' => $prospectiveGeneration - 1]) {
      throw new MigrateRefusal('rollback_unqualified');
    }
  }

  /**
   * Requires current generation and actual host writer exclusion.
   *
   * @internal
   */
  public function assertLockedRollback(string $domain, int $generation): void {
    if (!isset($this->fencedDomains[$domain])) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $row = $this->lockOperationAuthority($this->database, $domain);
    if ($row !== ['state' => 'agency', 'version' => $generation]) {
      throw new MigrateRefusal('rollback_stale_generation');
    }
  }

  /**
   * Runs the final rollback coordinator under the authority and host locks.
   *
   * The coordinator verifies its in-flight attempt before any replay. Unlike
   * the general fenced callback, this path can advance the rollback edge.
   *
   * @internal
   */
  public function runQualifiedRollback(RollbackService $coordinator, string $domain, string $reason, int $generation, bool $expected, RollbackAttempt $attempt): array {
    return $this->withTransitionFence($domain, 'legacy', $reason, TRUE, function () use ($coordinator, $domain, $reason, $generation, $expected, $attempt): array {
      $this->assertLockedRollback($domain, $generation);
      $receipt = $coordinator->applyUnderFence($domain, $reason, $generation, $expected, $attempt);
      $this->completeVerifiedRollback($domain, $reason, $generation);
      $attempt->readyToCommit = TRUE;
      return $receipt;
    });
  }

  /**
   * Completes the verified handover in its original fenced SQL transaction.
   */
  private function completeVerifiedRollback(string $domain, string $reason, int $generation): void {
    $this->assertLockedRollback($domain, $generation);
    $receipt = $this->database->select('agency_migrate_rollback_receipt', 'r')->fields('r', ['reason_hash'])
      ->condition('domain', $domain)->condition('generation', $generation)->execute()->fetchField();
    if ($receipt !== hash('sha256', trim($reason))) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->advanceLocked($domain, 'agency', 'legacy', $generation, $reason, TRUE);
    if ($this->lockOperationAuthority($this->database, $domain) !== ['state' => 'legacy', 'version' => $generation + 1]) {
      throw new MigrateRefusal('rollback_unqualified');
    }
  }

  /**
   * Writes the generation and its evidence in the caller's locked transaction.
   */
  private function advanceLocked(string $domain, string $from, string $to, int $generation, string $reason, bool $rollback): void {
    $values = $this->metadata($to, $generation + 1, $reason);
    $updated = $this->database->update('agency_migrate_authority_state')->fields($values)
      ->condition('domain', $domain)->condition('version', $generation)->condition('state', $from)->execute();
    if ($updated !== 1) {
      throw new MigrateRefusal('invalid_transition');
    }
    $this->recordEvent($domain, $from, $values, $rollback);
  }

  /**
   * Validates a snapshot without writing, locking or reserving anything.
   *
   * @return string
   *   The current recorded source state, not a concurrency guarantee.
   */
  public function assertTransition(string $domain, string $to, string $reason, bool $rollback = FALSE): string {
    $this->assertDomain($domain);
    $this->assertReason($reason);
    $this->assertPermission('administer agency migrate authority');
    return $this->validateTransition($domain, $to, $rollback, $this->get($domain));
  }

  /**
   * Validates either a read-only snapshot or an explicitly locked current row.
   */
  private function validateTransition(string $domain, string $to, bool $rollback, ?string $from): string {
    if (!in_array($to, self::STATES, TRUE) || $from === NULL || !$this->allowed($from, $to, $rollback)) {
      throw new MigrateRefusal('invalid_transition');
    }
    if ($this->requiresFence($from, $to)) {
      $this->writerFence->assertAvailable($domain);
    }
    return $from;
  }

  /**
   * Runs an executing transition/replay inside locked, freshly checked state.
   *
   * Host exclusion is acquired before the callback can load pending writes or
   * mutate entities. Row and host database locks survive savepoint release and
   * remain held until the root transaction completes. A dry run must not call
   * this method. The callback must not commit the caller's root transaction.
   */
  public function withTransitionFence(string $domain, string $to, string $reason, bool $rollback, callable $work): mixed {
    $this->assertTransition($domain, $to, $reason, $rollback);
    $root = !$this->database->inTransaction();
    $transaction = $this->database->startTransaction();
    $owned_fence = FALSE;
    try {
      $locked = $this->row($domain, TRUE);
      $from = $this->validateTransition($domain, $to, $rollback, $locked['state'] ?? NULL);
      if ($to === 'agency' && !$root && (($locked['disposition_provider'] ?? NULL) !== NULL || $this->dispositions->declaration($domain) !== NULL)) {
        throw new MigrateRefusal('writer_transaction_owned');
      }
      if ($this->requiresFence($from, $to) && !isset($this->fencedDomains[$domain])) {
        $this->writerFence->acquire($domain);
        $this->fencedDomains[$domain] = TRUE;
        $owned_fence = TRUE;
      }
      $result = $work();
      TransactionBoundary::commit($transaction);
      return $result;
    }
    catch (\Throwable $exception) {
      if ($this->database->inTransaction()) {
        $transaction?->rollBack();
      }
      throw $exception;
    }
    finally {
      if ($owned_fence) {
        unset($this->fencedDomains[$domain]);
      }
    }
  }

  /**
   * Fresh committed/current-transaction row, optionally locked until root end.
   */
  private function row(string $domain, bool $lock = FALSE): ?array {
    $this->assertStorage();
    $query = $this->database->select('agency_migrate_authority_state', 'a')->fields('a')->condition('domain', $domain);
    if ($lock) {
      $query->forUpdate();
    }
    $row = $query->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    unset($row['domain']);
    return $this->validatedRow($row);
  }

  /**
   * Refuses corrupt operational state rather than interpreting a safe default.
   */
  private function validatedRow(array $row): array {
    if (!in_array($row['state'] ?? NULL, self::STATES, TRUE) || (int) ($row['version'] ?? 0) < 1) {
      throw new MigrateRefusal('authority_storage_unavailable');
    }
    $row['version'] = (int) $row['version'];
    $row['changed'] = (int) $row['changed'];
    $row['disposition_provider'] ??= NULL;
    $required_after = $row['disposition_required_after'] ?? NULL;
    $row['disposition_required_after'] = $required_after === NULL ? NULL : (int) $required_after;
    if (($row['disposition_provider'] === NULL) !== ($row['disposition_required_after'] === NULL)
      || ($row['disposition_provider'] !== NULL && !RollbackDispositionRegistry::validProviderId($row['disposition_provider']))
      || ($required_after !== NULL && ((int) $required_after < 1 || (int) $required_after > $row['version']))) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $row;
  }

  /**
   * Requires the operational schema and engines with verified row exclusion.
   */
  private function assertStorage(): void {
    if (!in_array($this->database->driver(), ['mysql', 'pgsql'], TRUE)
      || !$this->database->schema()->tableExists('agency_migrate_authority_state')) {
      throw new MigrateRefusal('authority_storage_unavailable');
    }
  }

  /**
   * Whether one state-machine edge is legal.
   */
  private function allowed(string $from, string $to, bool $rollback): bool {
    if ($rollback) {
      return $from === 'agency' && $to === 'legacy';
    }
    return match ($from) {
      'legacy' => $to === 'shadow',
      'shadow' => $to === 'agency',
      'agency' => $to === 'removed',
      default => FALSE,
    };
  }

  /**
   * Changes involving authoritative Agency state need host writer coverage.
   */
  private function requiresFence(string $from, string $to): bool {
    return $from === 'agency' || $to === 'agency' || $to === 'removed';
  }

  /**
   * Builds immutable metadata for one operational generation.
   */
  private function metadata(string $state, int $version, string $reason): array {
    return [
      'state' => $state,
      'version' => $version,
      'changed' => $this->time->getCurrentTime(),
      'actor_hash' => hash('sha256', 'uid:' . $this->currentUser->id()),
      'reason' => trim($reason),
    ];
  }

  /**
   * Stores an event and evidence intent in the same authority transaction.
   */
  private function recordEvent(string $domain, string $from, array $values, bool $rollback): void {
    $this->database->insert('agency_migrate_authority_event')->fields([
      'domain' => $domain,
      'from_state' => $from,
      'to_state' => $values['state'],
      'actor_hash' => $values['actor_hash'],
      'reason' => $values['reason'],
      'recorded' => $values['changed'],
    ])->execute();
    $metadata = [
      'domain' => $domain,
      'from' => $from,
      'to' => $values['state'],
      'version' => $values['version'],
      'rollback' => $rollback,
    ];
    $fingerprint = hash('sha256', implode(':', ['authority', $domain, (string) $values['version']]));
    $intent_id = $this->outbox->record('authority_change', $metadata, $fingerprint);
    if ($rollback) {
      $this->assertRollbackIntent($intent_id, $fingerprint, $metadata);
    }
  }

  /**
   * Requires the exact new authority intent to be durably queued at handover.
   */
  private function assertRollbackIntent(string $id, string $fingerprint, array $expected): void {
    $row = $this->database->select('agency_migrate_outbox', 'o')->fields('o')
      ->condition('id', $id)->execute()->fetchAssoc();
    if (!$row || $row['operation'] !== 'authority_change' || $row['fingerprint'] !== $fingerprint || $row['delivered'] !== NULL) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $metadata = json_decode($row['metadata'], TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($metadata) || ($metadata['event_id'] ?? NULL) !== $id || ($metadata['effective_at'] ?? NULL) !== (int) $row['recorded']) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    unset($metadata['event_id'], $metadata['effective_at']);
    ksort($metadata);
    ksort($expected);
    if ($metadata !== $expected) {
      throw new MigrateRefusal('rollback_unqualified');
    }
  }

  /**
   * Fails closed when the current account lacks the named permission.
   */
  public function assertPermission(string $permission): void {
    if (!$this->currentUser->hasPermission($permission)) {
      throw new MigrateRefusal('forbidden');
    }
  }

  /**
   * Restricts domain ids to tenant-neutral machine names.
   */
  private function assertDomain(string $domain): void {
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $domain)) {
      throw new MigrateRefusal('invalid_transition');
    }
  }

  /**
   * Requires a short, nonempty operator reason.
   */
  private function assertReason(string $reason): void {
    if (trim($reason) === '' || strlen($reason) > 255) {
      throw new MigrateRefusal('invalid_transition');
    }
  }

}
