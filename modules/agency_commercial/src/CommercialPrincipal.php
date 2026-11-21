<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Authenticated commercial caller. Tenant comes from the hatch, not input.
 */
final readonly class CommercialPrincipal {

  /**
   * Constructs a commercial principal from a tenant claim and roles.
   *
   * @param string|null $tenantId
   *   Tenant claim, or NULL when the hatch omitted it.
   * @param list<string> $roles
   *   Token roles. Unused for G2 authority checks.
   */
  public function __construct(
    public ?string $tenantId,
    public array $roles = [],
  ) {}

  /**
   * Returns the tenant claim or refuses a missing or blank claim.
   *
   * @return string
   *   Non-empty tenant identifier from the hatch.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   When the claim is NULL or blank.
   */
  public function requireTenant(): string {
    if ($this->tenantId === NULL || trim($this->tenantId) === '') {
      throw new CommercialRefusal(CommercialRefusal::MISSING_TENANT_CLAIM);
    }
    return $this->tenantId;
  }

}
