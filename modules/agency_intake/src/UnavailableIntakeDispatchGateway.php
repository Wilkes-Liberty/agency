<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Default dispatch gateway. Throws until a host adapter is supplied.
 */
final class UnavailableIntakeDispatchGateway implements IntakeDispatchGatewayInterface {

  /**
   * {@inheritdoc}
   */
  public function dispatch(string $dispatch_key, mixed $intent): void {
    throw new IntakeRefusal('unavailable');
  }

}
