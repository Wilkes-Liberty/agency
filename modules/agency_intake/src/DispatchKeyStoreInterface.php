<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Remembers dispatch keys so a second send can be refused.
 *
 * Occupancy is reserved, uncertain or sent. A digest binds one key.
 * Duplicate sent keys and a new key for a bound digest are not_found.
 * Retry of reserved or uncertain occupancy with the same digest is
 * allowed. Empty key or digest is not_found.
 */
interface DispatchKeyStoreInterface {

  /**
   * Reserves one dispatch key without sending.
   *
   * @param string $key
   *   Opaque idempotency key for this intent.
   * @param string $intent_digest
   *   Digest of the intent bound to this key.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Empty key or digest, duplicate sent key, or a new key for a
   *   digest already bound to another key.
   */
  public function reserve(string $key, string $intent_digest = ''): void;

  /**
   * Returns occupancy for one key.
   *
   * @param string|null $key
   *   Opaque idempotency key, or NULL when none was supplied.
   *
   * @return string|null
   *   reserved, uncertain or sent, or NULL when unknown.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Default implementation throws unavailable.
   */
  public function occupancy(?string $key): ?string;

  /**
   * Records that transport did not confirm delivery for this key.
   *
   * @param string $key
   *   Opaque idempotency key already reserved.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Default implementation throws unavailable.
   */
  public function rememberUncertain(string $key): void;

  /**
   * Records that transport accepted this key.
   *
   * @param string $key
   *   Opaque idempotency key already reserved.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Default implementation throws unavailable.
   */
  public function rememberSent(string $key): void;

}
