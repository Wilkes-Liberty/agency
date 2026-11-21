<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Token-derived caller for a storage-free engagement action.
 *
 * Tenant and subject come from the token. A hatch principal has no tenant.
 */
final class EngagementPrincipal {

  public function __construct(
    public readonly string $id,
    public readonly string $tenant,
    public readonly bool $hatch = FALSE,
  ) {
    if ($id === '' || str_contains($id, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
    if (str_contains($tenant, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
  }

}
