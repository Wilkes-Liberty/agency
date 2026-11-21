<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Default queued-delivery port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Existing intake rows are never dumped.
 * Remote dispatch stays host. Consumed-key replay is not_found.
 */
final class UnavailableIntakeDeliveryWorker implements
  IntakeDeliveryWorkerInterface {

  /**
   * {@inheritdoc}
   */
  public function process(IntakePrincipal $principal, string $id): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

}
