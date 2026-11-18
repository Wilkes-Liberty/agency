<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Standard local SQL storage exposing its actual transaction connection.
 *
 * Hosts select this handler explicitly. Custom handlers must independently
 * implement the participant contract; ordinary SQL interface membership alone
 * does not demonstrate that their mutations share the operation transaction.
 */
class OperationSqlContentEntityStorage extends SqlContentEntityStorage implements OperationStorageParticipantInterface {

  /**
   * {@inheritdoc}
   */
  final public function operationConnection(): Connection {
    return $this->database;
  }

}
