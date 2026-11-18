<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Exception;

/**
 * Reports a profile read with unavailable required evidence.
 */
final class WorkforceProfileReadUnavailable extends \RuntimeException {

  public function __construct() {
    parent::__construct('The workforce profile read is unavailable.');
  }

}
