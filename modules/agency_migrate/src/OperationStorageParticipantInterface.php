<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\Database\Connection;

/**
 * An explicitly qualified local storage participant, not a backend guess.
 */
interface OperationStorageParticipantInterface {

  /**
   * Returns the actual connection used by this storage's entity mutations.
   */
  public function operationConnection(): Connection;

}
