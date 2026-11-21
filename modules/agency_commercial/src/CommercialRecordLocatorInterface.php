<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Locates a commercial record's tenant without returning a payload.
 *
 * Slice 3 G1 is accepted. This package still ships no entity types.
 */
interface CommercialRecordLocatorInterface {

  /**
   * Returns the owning tenant for one commercial record id.
   *
   * @param string $id
   *   Opaque commercial record identifier.
   *
   * @return array{tenant_id: string}|null
   *   Tenant scope, or NULL when the id is unknown.
   */
  public function locate(string $id): ?array;

}
