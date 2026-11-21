<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Tenant-scoped governed intake-tools hatch. Never dumps delivery state.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Missing and foreign lookups share not_found. Vendor identifiers stay
 * host. The hatch never grants a permission.
 */
interface IntakeToolsInterface {

  /**
   * Reads delivery occupancy for one intake identifier.
   *
   * Default implementation never returns a status payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string}
   *   Found outcome without payload or vendor fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, missing id, or foreign tenant.
   */
  public function status(IntakePrincipal $principal, string $id): array;

  /**
   * Looks up one delivery by opaque external key.
   *
   * Default implementation never returns a matching identifier.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $key
   *   Opaque external delivery key.
   *
   * @return array{outcome: string}
   *   Found outcome without payload or vendor fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, missing key, or foreign tenant.
   */
  public function lookup(IntakePrincipal $principal, string $key): array;

  /**
   * Lists pending deliveries for this tenant.
   *
   * Default implementation never returns a pending list.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   *
   * @return array{outcome: string}
   *   Found outcome without payload or vendor fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, or no visible pending occupancy.
   */
  public function pending(IntakePrincipal $principal): array;

}
