<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Read;

/**
 * Resolves verified workforce bindings and projects an authorized profile.
 */
interface WorkforceProfileSourceInterface {

  /**
   * Reads binding metadata only for an exact workforce Person UUID.
   *
   * The binding must use a reviewed workforce identity link. A profile,
   * email, owner UID or Discovery grant alone supplies no authority. Return
   * NULL for a missing, ambiguous or unverified mapping. Do not load its
   * profile, evidence or protected files to answer this lookup.
   */
  public function bindingFor(string $personUuid): ?WorkforcePersonBinding;

  /**
   * Reads the own-profile display name under a current binding and grant.
   *
   * Check the exact binding and current workforce entitlement again at the
   * protected read. Use a consistent storage snapshot or writer exclusion
   * so a reassignment or revocation cannot invalidate that check before the
   * value is loaded. Return NULL for an unavailable or unauthorized value.
   * Complete any adapter-owned transaction before returning.
   */
  public function readDisplayName(WorkforcePersonBinding $binding): ?string;

}
