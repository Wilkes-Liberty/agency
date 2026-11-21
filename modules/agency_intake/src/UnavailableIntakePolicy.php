<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Default policy port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Catalog values, recipients and vendor
 * mappings are never dumped.
 */
final class UnavailableIntakePolicy implements IntakePolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function allows(
    IntakePrincipal $principal,
    string $form_id,
    string $action,
  ): bool {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(
    IntakePrincipal $principal,
    string $form_id,
  ): array {
    $principal->requireTenant();
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

}
