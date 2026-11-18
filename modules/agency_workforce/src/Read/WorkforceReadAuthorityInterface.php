<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Read;

/**
 * Resolves current workforce authority from a trusted host context.
 */
interface WorkforceReadAuthorityInterface {

  /**
   * Freshly resolves verified claims and the effective workforce entitlement.
   *
   * Use the authenticated issuer, subject and tenant and an independent,
   * current workforce read entitlement. Consult revocation, expiry and
   * suspension each time. Return NULL when verification is unavailable.
   * Request parameters and Discovery grants cannot supply authority.
   *
   * @phpstan-impure
   */
  public function current(): ?WorkforceReadAuthority;

}
