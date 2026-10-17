<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\Core\Database\Connection;

/**
 * Session locks shared by evidence workers and every health-monitor caller.
 */
final class EvidenceLock {

  /**
   * Purposes acquired by this service instance.
   *
   * @var array<string, bool>
   */
  private array $owned = [];

  public function __construct(private readonly Connection $database) {}

  /**
   * Tries once; a live session never loses ownership through lease expiry.
   */
  public function acquire(string $purpose): bool {
    $parameters = $this->parameters($purpose);
    if ($this->database->getConnectionOptions()['driver'] !== 'pgsql' || $this->database->inTransaction()) {
      throw new \LogicException('Evidence maintenance requires PostgreSQL outside a transaction.');
    }
    if (isset($this->owned[$purpose])) {
      return FALSE;
    }
    $result = $this->database->query('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:table))', $parameters)->fetchField();
    if (!in_array($result, [TRUE, 1, '1', 't'], TRUE)) {
      return FALSE;
    }
    $this->owned[$purpose] = TRUE;
    return TRUE;
  }

  /**
   * Releases only a lock acquired by this service instance.
   */
  public function release(string $purpose): void {
    if (!isset($this->owned[$purpose])) {
      throw new \LogicException('Evidence lock is not owned.');
    }
    $result = $this->database->query('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:table))', $this->parameters($purpose))->fetchField();
    if (!in_array($result, [TRUE, 1, '1', 't'], TRUE)) {
      throw new \RuntimeException('Evidence lock was not released.');
    }
    unset($this->owned[$purpose]);
  }

  /**
   * The table prefix separates installations sharing the same database.
   */
  private function parameters(string $purpose): array {
    if (!in_array($purpose, ['worker', 'monitor'], TRUE)) {
      throw new \InvalidArgumentException('Unknown evidence lock purpose.');
    }
    return [
      ':namespace' => 'agency_host.evidence_' . $purpose,
      ':table' => $this->database->prefixTables('{agency_staff_outbox}'),
    ];
  }

}
