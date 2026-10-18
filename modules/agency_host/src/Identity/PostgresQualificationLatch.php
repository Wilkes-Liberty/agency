<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

/**
 * Append-only latch committed independently of the Agency root transaction.
 *
 * This connection never reads, joins or locks authmap/history/coverage rows.
 * Production qualification requires PostgreSQL; no replica may serve this port.
 */
final class PostgresQualificationLatch implements QualificationLatchInterface {

  /**
   * Independent connection used for durable invalidation.
   */
  private ?Connection $connection = NULL;

  /**
   * The uniquely owned Database connection registration.
   */
  private ?string $connectionKey = NULL;

  public function __construct(private readonly Connection $primary) {}

  /**
   * {@inheritdoc}
   */
  public function arm(string $epoch): string {
    $this->validate($epoch);
    $guard = bin2hex(random_bytes(32));
    try {
      $this->connection()->insert('agency_host_binding_guard')
        ->fields(['id' => $guard, 'epoch' => $epoch])->execute();
      return $guard;
    }
    catch (\Throwable $error) {
      throw new IdentityDirectoryUnavailable('Binding verification guard is unavailable.', 0, $error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(string $guard): void {
    $this->validate($guard);
    try {
      if ($this->connection()->delete('agency_host_binding_guard')->condition('id', $guard)->execute() !== 1) {
        throw new \RuntimeException('Verification guard was not owned or was already resolved.');
      }
    }
    catch (\Throwable $error) {
      throw new IdentityDirectoryUnavailable('Binding verification guard could not resolve.', 0, $error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate(string $epoch): void {
    $this->validate($epoch);
    try {
      $this->connection()->query('INSERT INTO {agency_host_binding_invalid} (epoch) VALUES (:epoch) ON CONFLICT (epoch) DO NOTHING', [':epoch' => $epoch]);
    }
    catch (\Throwable $error) {
      throw new IdentityDirectoryUnavailable('Binding invalidation storage is unavailable.', 0, $error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidated(string $epoch): bool {
    $this->validate($epoch);
    try {
      return (bool) $this->connection()->select('agency_host_binding_invalid', 'i')->fields('i', ['epoch'])
        ->condition('epoch', $epoch)->execute()->fetchField()
        || (bool) $this->connection()->select('agency_host_binding_guard', 'g')->fields('g', ['id'])
          ->condition('epoch', $epoch)->execute()->fetchField();
    }
    catch (\Throwable $error) {
      throw new IdentityDirectoryUnavailable('Binding invalidation storage is unavailable.', 0, $error);
    }
  }

  /**
   * Opens a separate connection to the same primary database and table prefix.
   */
  private function connection(): Connection {
    if ($this->connection === NULL) {
      $options = $this->primary->getConnectionOptions();
      if ($options['driver'] !== 'pgsql') {
        throw new IdentityDirectoryUnavailable('Durable qualification requires PostgreSQL.');
      }
      $key = 'agency_host_latch_' . bin2hex(random_bytes(16));
      $this->connectionKey = $key;
      Database::addConnectionInfo($key, 'default', $options);
      $this->connection = Database::getConnection('default', $key);
      $this->connection->query("SET lock_timeout = '3s'");
      $this->connection->query("SET statement_timeout = '5s'");
    }
    return $this->connection;
  }

  /**
   * Removes only the uniquely owned registration when the service is released.
   */
  public function __destruct() {
    if ($this->connectionKey !== NULL) {
      Database::removeConnection($this->connectionKey);
    }
  }

  /**
   * Epochs are opaque qualification IDs, never mutable map fingerprints.
   */
  private function validate(string $epoch): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $epoch)) {
      throw new IdentityDirectoryUnavailable('Binding qualification epoch is unavailable.');
    }
  }

}
