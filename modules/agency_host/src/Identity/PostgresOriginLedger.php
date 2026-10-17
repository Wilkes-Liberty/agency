<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

/**
 * Never overwrites a consumption record or recycles a failed reservation.
 */
final class PostgresOriginLedger implements OriginLedgerInterface {

  /**
   * Independently owned primary connection.
   */
  private ?Connection $connection = NULL;

  /**
   * Registration belonging only to this service instance.
   */
  private ?string $connectionKey = NULL;

  public function __construct(private readonly Connection $primary) {}

  /**
   * {@inheritdoc}
   */
  public function find(string $issuerKey): ?array {
    $record = $this->connection()->select('agency_host_binding_origin', 'o')->fields('o')
      ->condition('issuer_key', $issuerKey)->execute()->fetchAssoc();
    if (!$record) {
      return NULL;
    }
    $record['created'] = (int) $record['created'];
    $record['completed'] = (int) $record['completed'];
    return $record;
  }

  /**
   * {@inheritdoc}
   */
  public function readCommitted(string $issuerKey): ?array {
    // Never consult the connection that attempted an uncertain completion: it
    // might still see its own uncommitted update. A new instance creates a new
    // primary connection and sees only durable records, even if the old session
    // remains alive. Its independently owned registration is then released.
    $reader = new self($this->primary);
    return $reader->find($issuerKey);
  }

  /**
   * {@inheritdoc}
   */
  public function reserve(array $record): void {
    $database = $this->connection();
    if (!$this->primary->inTransaction() || $database->inTransaction()) {
      throw new IdentityDirectoryUnavailable('Origin reservation is unavailable.');
    }
    $transaction = $database->startTransaction();
    try {
      $database->insert('agency_host_binding_origin')->fields($record)->execute();
      $database->insert('agency_host_binding_guard')
        ->fields(['id' => $record['guard'], 'epoch' => $record['epoch']])->execute();
      $transaction->commitOrRelease();
    }
    catch (\Throwable) {
      OriginTransaction::rollBack($transaction);
      throw new IdentityDirectoryUnavailable('Origin reservation is unavailable.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function complete(array $record, int $completed): void {
    $database = $this->connection();
    if (!$this->primary->inTransaction() || $database->inTransaction()) {
      throw new IdentityDirectoryUnavailable('Origin completion is unavailable.');
    }
    $transaction = $database->startTransaction();
    try {
      $current = $this->find($record['issuer_key']);
      foreach ($record as $key => $value) {
        if (($current[$key] ?? NULL) !== $value) {
          throw new \UnexpectedValueException();
        }
      }
      $guards = $database->select('agency_host_binding_guard', 'g')->fields('g', ['id'])
        ->condition('epoch', $record['epoch'])->execute()->fetchCol();
      if ($record['state'] !== 'reserved' || $guards !== [$record['guard']]
        || $database->select('agency_host_binding_invalid', 'i')->fields('i', ['epoch'])
          ->condition('epoch', $record['epoch'])->execute()->fetchField()) {
        throw new \UnexpectedValueException();
      }
      if ($database->update('agency_host_binding_origin')
        ->fields(['state' => 'completed', 'completed' => $completed])
        ->condition('issuer_key', $record['issuer_key'])->condition('state', 'reserved')->execute() !== 1
        || $database->delete('agency_host_binding_guard')
          ->condition('id', $record['guard'])->condition('epoch', $record['epoch'])->execute() !== 1) {
        throw new \UnexpectedValueException();
      }
      $transaction->commitOrRelease();
    }
    catch (\Throwable) {
      OriginTransaction::rollBack($transaction);
      throw new IdentityDirectoryUnavailable('Origin completion is unavailable.');
    }
  }

  /**
   * Uses the primary database and prefix, never a replica or caller-selected DB.
   */
  private function connection(): Connection {
    if ($this->connection === NULL) {
      $options = $this->primary->getConnectionOptions();
      if ($options['driver'] !== 'pgsql') {
        throw new IdentityDirectoryUnavailable('Origin ledger requires PostgreSQL.');
      }
      $this->connectionKey = 'agency_host_origin_' . bin2hex(random_bytes(16));
      Database::addConnectionInfo($this->connectionKey, 'default', $options);
      $this->connection = Database::getConnection('default', $this->connectionKey);
      $this->connection->query("SET lock_timeout = '3s'");
      $this->connection->query("SET statement_timeout = '5s'");
    }
    return $this->connection;
  }

  /**
   * Releases only this service's independently owned connection registration.
   */
  public function __destruct() {
    if ($this->connectionKey !== NULL) {
      Database::removeConnection($this->connectionKey);
    }
  }

}
