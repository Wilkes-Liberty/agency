<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Fixtures;

use Drupal\Core\Database\Connection;

/**
 * Rejects a real journal or evidence insert after the entity has been saved.
 */
final class MigrateOperationSqlFault {

  private readonly string $name;

  public function __construct(
    private readonly Connection $database,
    private readonly string $table,
  ) {
    if (!in_array($table, ['agency_migrate_write_log', 'agency_migrate_outbox'], TRUE)) {
      throw new \InvalidArgumentException('Unknown operation fault.');
    }
    $this->name = 'agency_operation_fault_' . bin2hex(random_bytes(6));
  }

  /**
   * Rejects inserts without changing transaction behavior or installed data.
   */
  public function install(): void {
    $options = ['allow_delimiter_in_query' => TRUE];
    if ($this->database->driver() === 'pgsql') {
      $this->database->query("CREATE FUNCTION $this->name() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic operation boundary failure''; END'", [], $options);
      $this->database->query("CREATE TRIGGER $this->name BEFORE INSERT ON {{$this->table}} FOR EACH ROW EXECUTE FUNCTION $this->name()");
    }
    else {
      $this->database->query("CREATE TRIGGER $this->name BEFORE INSERT ON {{$this->table}} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic operation boundary failure'", [], $options);
    }
  }

  /**
   * Removes the fault after the root rollback has completed.
   */
  public function remove(): void {
    if ($this->database->driver() === 'pgsql') {
      $this->database->query("DROP TRIGGER IF EXISTS $this->name ON {{$this->table}}");
      $this->database->query("DROP FUNCTION IF EXISTS $this->name()");
    }
    else {
      $this->database->query("DROP TRIGGER IF EXISTS $this->name");
    }
  }

}
