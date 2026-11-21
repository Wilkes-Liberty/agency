<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Default governed-tools hatch. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Delivery markers, external keys and pending
 * lists are never dumped.
 */
final class UnavailableIntakeTools implements IntakeToolsInterface {

  /**
   * {@inheritdoc}
   */
  public function status(IntakePrincipal $principal, string $id): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(IntakePrincipal $principal, string $key): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function pending(IntakePrincipal $principal): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

}
