<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\Core\Database\Connection;

/**
 * Rejects a real delivery marker after the public Audit Chain append.
 */
final class DeliveryMarkerFault {

  /**
   * Unique trigger/function name belonging to this synthetic fault.
   */
  private readonly string $name;

  public function __construct(private readonly Connection $database) {
    $this->name = 'workforce_marker_fault_' . bin2hex(random_bytes(6));
  }

  /**
   * Installs the failure on a real database write, not on the logger mock.
   */
  public function install(): void {
    $options = ['allow_delimiter_in_query' => TRUE];
    if ($this->database->driver() === 'pgsql') {
      $this->database->query("CREATE FUNCTION $this->name() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic workforce marker failure''; END'", [], $options);
      $this->database->query("CREATE TRIGGER $this->name BEFORE UPDATE ON {agency_workforce_outbox} FOR EACH ROW WHEN (NEW.delivered IS NOT NULL) EXECUTE FUNCTION $this->name()");
      return;
    }
    if ($this->database->driver() === 'mysql') {
      $this->database->query("CREATE TRIGGER $this->name BEFORE UPDATE ON {agency_workforce_outbox} FOR EACH ROW BEGIN IF NEW.delivered IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic workforce marker failure'; END IF; END", [], $options);
      return;
    }
    throw new \LogicException('Marker proof requires PostgreSQL or MySQL.');
  }

  /**
   * Removes the test failure before delivery retries.
   */
  public function remove(): void {
    if ($this->database->driver() === 'pgsql') {
      $this->database->query("DROP TRIGGER IF EXISTS $this->name ON {agency_workforce_outbox}");
      $this->database->query("DROP FUNCTION IF EXISTS $this->name()");
      return;
    }
    $this->database->query("DROP TRIGGER IF EXISTS $this->name");
  }

}
