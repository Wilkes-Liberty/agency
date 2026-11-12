<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Fixtures;

use Drupal\Core\Database\Connection;

/**
 * Rejects a synthetic second batch at a real database write boundary.
 */
final class MigrateBatchSqlFault {

  /**
   * Unique trigger and function name for this synthetic fault.
   */
  private readonly string $name;

  /**
   * Table whose write should fail at the selected boundary.
   */
  private readonly string $table;

  public function __construct(
    private readonly Connection $database,
    private readonly string $point,
  ) {
    $this->name = 'agency_batch_fault_' . bin2hex(random_bytes(6));
    $this->table = match ($point) {
      'target_before_cursor' => 'agency_migrate_run',
      'cursor_before_outbox', 'outbox_failure' => 'agency_migrate_outbox',
      default => throw new \InvalidArgumentException('Unknown batch fault.'),
    };
  }

  /**
   * Installs a trigger, leaving the first two-record batch unaffected.
   */
  public function install(): void {
    $event = $this->point === 'target_before_cursor' ? 'UPDATE' : 'INSERT';
    $timing = $this->point === 'outbox_failure' ? 'AFTER' : 'BEFORE';
    $condition = $this->point === 'target_before_cursor'
      ? 'NEW.cursor_source_id = 3'
      : "NEW.operation = 'lineage_batch' AND NEW.metadata LIKE '%\"id_from\":3,%'";
    $options = ['allow_delimiter_in_query' => TRUE];
    if ($this->database->driver() === 'pgsql') {
      $body = "BEGIN IF $condition THEN RAISE EXCEPTION 'synthetic batch boundary failure'; END IF; RETURN NEW; END";
      $body = str_replace("'", "''", $body);
      $this->database->query("CREATE FUNCTION $this->name() RETURNS trigger LANGUAGE plpgsql AS '$body'", [], $options);
      $this->database->query("CREATE TRIGGER $this->name $timing $event ON {{$this->table}} FOR EACH ROW EXECUTE FUNCTION $this->name()");
      return;
    }
    if ($this->database->driver() === 'mysql') {
      $this->database->query("CREATE TRIGGER $this->name $timing $event ON {{$this->table}} FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic batch boundary failure'; END IF; END", [], $options);
      return;
    }
    throw new \LogicException('Batch fault proofs require PostgreSQL or MySQL.');
  }

  /**
   * Removes the fault before resuming the same run.
   */
  public function remove(): void {
    if ($this->database->driver() === 'pgsql') {
      $this->database->query("DROP TRIGGER IF EXISTS $this->name ON {{$this->table}}");
      $this->database->query("DROP FUNCTION IF EXISTS $this->name()");
      return;
    }
    $this->database->query("DROP TRIGGER IF EXISTS $this->name");
  }

}
