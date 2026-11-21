<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Default outbox port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Existing intake rows are never dumped.
 * Remote dispatch stays host.
 */
final class UnavailableIntakeOutbox implements IntakeOutboxInterface {

  /**
   * {@inheritdoc}
   */
  public function enqueue(IntakePrincipal $principal, string $id): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function process(
    IntakePrincipal $principal,
    string $id,
    string $action,
  ): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

}
