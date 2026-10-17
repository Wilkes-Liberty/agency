<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\Core\KeyValueStore\DatabaseStorageExpirable;

/**
 * Keeps failed expiring-payload reads distinct from an expired review.
 */
final class StrictConsolePayloadStorage extends DatabaseStorageExpirable {

  /**
   * {@inheritdoc}
   */
  protected function catchException(\Exception $e) {
    throw $e;
  }

}
