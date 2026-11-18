<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Read;

/**
 * Carries the verified principal and effective workforce read entitlement.
 */
final class WorkforceReadAuthority {

  public function __construct(
    public readonly string $issuer,
    public readonly string $subject,
    public readonly string $tenant,
    public readonly bool $mayReadOwnProfile,
  ) {}

  /**
   * Requires a complete verified principal and an effective entitlement.
   */
  public function allowsOwnProfile(): bool {
    return $this->mayReadOwnProfile
      && trim($this->issuer) !== ''
      && trim($this->subject) !== ''
      && trim($this->tenant) !== '';
  }

  /**
   * Compares the binding's complete identity without normalizing claims.
   */
  public function allowsBinding(WorkforcePersonBinding $binding): bool {
    return $this->allowsOwnProfile()
      && $this->issuer === $binding->issuer
      && $this->subject === $binding->subject
      && $this->tenant === $binding->tenant;
  }

}
