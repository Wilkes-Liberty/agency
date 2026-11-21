<?php

declare(strict_types=1);

namespace Drupal\agency_intake\Exception;

/**
 * Timeout or 5xx from host transport. Retry reuses the original key.
 *
 * The message never includes a dispatch identifier.
 */
final class UncertainDelivery extends \RuntimeException {

  /**
   * Constructs an uncertain-delivery signal without a dispatch identifier.
   */
  public function __construct() {
    parent::__construct('uncertain');
  }

}
