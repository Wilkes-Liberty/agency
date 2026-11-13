<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Remembers dispatch keys so a second send can be refused later.
 *
 * CRM transfer §5. Occupancy and uncertain-delivery semantics stay open.
 *
 * @todo S3 G1 D7 Store occupancy and uncertain-delivery stay open.
 */
interface DispatchKeyStoreInterface {

  /**
   * Reserves one dispatch key without sending.
   *
   * @param string $dispatch_key
   *   Opaque key. Duplicate occupancy is a G1 contract, not this default.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   */
  public function reserve(string $dispatch_key): void;

}
