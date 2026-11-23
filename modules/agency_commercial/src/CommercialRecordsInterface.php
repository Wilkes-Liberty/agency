<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Tenant-scoped commercial record port. Never returns an entity payload.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Missing and foreign records share not_found.
 */
interface CommercialRecordsInterface {

  /**
   * Loads one commercial record for the hatch tenant.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque commercial record identifier.
   *
   * @return array{outcome: string, id: string}
   *   Found outcome without tenant scope or entity fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, missing id, or foreign tenant.
   */
  public function load(CommercialPrincipal $principal, string $id): array;

  /**
   * Applies an authorized field update. Stage changes are not a bypass.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque commercial record identifier.
   * @param array<string, mixed> $values
   *   Requested field values. Default implementation never writes.
   *
   * @return array{outcome: string, id: string}
   *   Updated outcome without entity fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, missing id, foreign tenant, or stage bypass.
   */
  public function update(
    CommercialPrincipal $principal,
    string $id,
    array $values,
  ): array;

}
