<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_disposition_test;

use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\RollbackDispositionInterface;
use Drupal\agency_migrate\RollbackDispositionPlan;
use Drupal\agency_migrate\TransactionBoundary;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;

/**
 * Reverses synthetic SQL resources on the real rollback transaction.
 */
final class LocalDisposition implements RollbackDispositionInterface {

  /**
   * Records participant construction for the lazy container proof.
   */
  public static int $instances = 0;

  /**
   * Failure injection for the next call.
   */
  public string $fault = '';

  /**
   * Optional entity expected in legacy storage before resource effects.
   */
  public ?int $expectedLegacyId = NULL;

  /**
   * Holds a deliberate violation open for assertion and fixture cleanup.
   */
  private ?Transaction $replacement = NULL;

  /**
   * Represents an accidentally retained participant savepoint.
   */
  private ?Transaction $child = NULL;

  /**
   * Observes transaction membership without mutating persistence.
   *
   * @var list<array{string, bool}>
   */
  public array $calls = [];

  public function __construct(
    private readonly Connection $database,
    private readonly AuthorityRegister $authority,
  ) {
    self::$instances++;
  }

  /**
   * {@inheritdoc}
   */
  public function qualifyCutover(Connection $connection, string $domain, int $prospectiveGeneration): void {
    $this->check($connection, 'cutover', TRUE);
    $this->attest($connection, fn () => $this->authority->assertLockedCutover($domain, $prospectiveGeneration));
    if ($this->fault === 'cutover' || $domain !== 'synthetic' || $prospectiveGeneration < 3) {
      throw new \LogicException('Synthetic cutover qualification failure.');
    }
    if ($this->fault === 'replace_cutover') {
      $this->replaceTransaction($connection);
    }
    if ($this->fault === 'native_cutover') {
      $this->client($connection)->commit();
    }
    if (str_starts_with($this->fault, 'child_cutover')) {
      $this->retainChild($connection, $domain, $prospectiveGeneration);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Connection $connection, string $domain, int $generation): RollbackDispositionPlan {
    $this->check($connection, 'plan');
    if ($this->fault === 'plan') {
      throw new \LogicException('Synthetic preflight refusal.');
    }
    $ids = $connection->select('agency_migrate_test_resource', 'r')->fields('r', ['id'])
      ->condition('domain', $domain)->condition('generation', $generation)
      ->condition('authority', 'agency')->orderBy('id')->execute()->fetchCol();
    return new LocalDispositionPlan($domain, $generation, array_map(intval(...), $ids));
  }

  /**
   * {@inheritdoc}
   */
  public function applyAndVerify(Connection $connection, RollbackDispositionPlan $plan): string {
    $this->check($connection, 'apply', TRUE);
    if (!$plan instanceof LocalDispositionPlan) {
      throw new \LogicException('Unexpected synthetic plan.');
    }
    if ($this->expectedLegacyId !== NULL && !(bool) $connection->select('agency_migrate_legacy_item', 'i')
      ->condition('id', $this->expectedLegacyId)->countQuery()->execute()->fetchField()) {
      throw new \LogicException('Missing legacy replay before disposition.');
    }
    $this->attest($connection, fn () => $this->authority->assertLockedRollback($plan->domain, $plan->generation));
    foreach ($plan->resourceIds() as $id) {
      $updates = $connection->update('agency_migrate_test_resource')->fields(['authority' => 'legacy'])
        ->condition('domain', $plan->domain)->condition('generation', $plan->generation)
        ->condition('id', $id)->condition('authority', 'agency')->execute();
      if ($updates !== 1 || $this->fault === 'partial') {
        throw new \LogicException('Synthetic resource reversal failure.');
      }
    }
    $digest = hash('sha256', json_encode([$plan->domain, $plan->generation, $plan->resourceIds()], JSON_THROW_ON_ERROR));
    $connection->insert('agency_migrate_test_disposition')->fields([
      'domain' => $plan->domain,
      'generation' => $plan->generation,
      'digest' => $digest,
      'changed' => $plan->pendingCount,
    ])->execute();
    if ($this->fault === 'readback') {
      $connection->update('agency_migrate_test_disposition')->fields(['digest' => str_repeat('0', 64)])->execute();
    }
    $this->assertReceipt($connection, $plan->domain, $plan->generation, $digest);
    if ($this->fault === 'commit') {
      $connection->transactionManager()->addPostTransactionCallback(static function (bool $success): void {
        if ($success) {
          throw new \RuntimeException('Synthetic lost disposition acknowledgement.');
        }
      });
    }
    if ($this->fault === 'replace_apply') {
      $this->replaceTransaction($connection);
    }
    if ($this->fault === 'native_apply') {
      $this->client($connection)->commit();
    }
    if (in_array($this->fault, ['native_ddl', 'native_ddl_throw'], TRUE)) {
      $this->client($connection)->exec($connection->prefixTables('ALTER TABLE {agency_migrate_test_resource} ADD COLUMN disposition_ddl_probe INT'));
      if ($this->fault === 'native_ddl_throw') {
        throw new \LogicException('Synthetic failure after transactional DDL.');
      }
    }
    if (str_starts_with($this->fault, 'child_rollback')) {
      $this->retainChild($connection, $plan->domain, $plan->generation);
    }
    return $this->fault === 'digest' ? 'invalid-proof' : $digest;
  }

  /**
   * Exercises attestation within a legitimate short-lived savepoint.
   */
  private function attest(Connection $connection, callable $assertion): void {
    if ($this->fault !== 'nested_attestation') {
      $assertion();
      return;
    }
    $transaction = $connection->startTransaction('synthetic_attestation');
    try {
      $assertion();
    }
    finally {
      TransactionBoundary::commit($transaction);
    }
  }

  /**
   * Releases the fixture handle and reports whether the provider retained it.
   */
  public function disposeChild(): bool {
    $retained = $this->child !== NULL;
    $this->child = NULL;
    return $retained;
  }

  /**
   * Writes a synthetic child effect and retains the live savepoint.
   */
  private function retainChild(Connection $connection, string $domain, int $generation): void {
    $this->child = $connection->startTransaction('synthetic_retained_child');
    $connection->insert('agency_migrate_test_resource')->fields([
      'domain' => $domain,
      'generation' => $generation,
      'id' => 2,
      'authority' => 'legacy',
    ])->execute();
    if (str_ends_with($this->fault, '_throw')) {
      throw new \LogicException('Synthetic retained child failure.');
    }
  }

  /**
   * Returns the actual synthetic PDO connection for fault injection.
   */
  private function client(Connection $connection): \PDO {
    $client = $connection->getClientConnection();
    if (!$client instanceof \PDO || !$client->inTransaction()) {
      throw new \LogicException('Missing native transaction for test fault.');
    }
    return $client;
  }

  /**
   * Drops a replacement that the production coordinator does not own.
   */
  public function discardReplacement(): void {
    if ($this->replacement !== NULL) {
      $this->replacement->rollBack();
      $this->replacement = NULL;
    }
  }

  /**
   * Deliberately violates the transaction ownership contract on real SQL.
   */
  private function replaceTransaction(Connection $connection): void {
    $connection->commitAll();
    $this->replacement = $connection->startTransaction();
  }

  /**
   * {@inheritdoc}
   */
  public function verifyCommitted(Connection $connection, string $domain, int $generation, string $digest): void {
    $this->check($connection, 'verify', FALSE);
    $this->assertReceipt($connection, $domain, $generation, $digest);
  }

  /**
   * Independently verifies the immutable host receipt on the supplied database.
   */
  private function assertReceipt(Connection $connection, string $domain, int $generation, string $digest): void {
    $persisted = $connection->select('agency_migrate_test_disposition', 'r')->fields('r', ['digest'])
      ->condition('domain', $domain)->condition('generation', $generation)->execute()->fetchField();
    if ($persisted !== $digest) {
      throw new \LogicException('Unexpected synthetic receipt.');
    }
  }

  /**
   * Verifies the serving connection and expected transaction state.
   */
  private function check(Connection $connection, string $call, ?bool $transaction = NULL): void {
    $active = $connection->inTransaction();
    $this->calls[] = [$call, $active];
    if ($connection !== $this->database || ($transaction !== NULL && $active !== $transaction)) {
      throw new \LogicException('Unexpected synthetic transaction.');
    }
  }

}
