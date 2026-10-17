<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Slice 2 workforce reads. Host adapters keep HTTP transport.
 *
 * Implementations revalidate identity, binding and workforce entitlement
 * on every call and must not project Discovery workspaces.
 */
interface WorkforceReadsInterface {

  /**
   * Returns invitation status only. GET never consumes or binds.
   */
  public function inspectInvitation(string $token): array;

  /**
   * Projects the current principal's allowlisted own profile.
   */
  public function ownProfile(): array;

  /**
   * Projects the current principal's allowlisted account summary.
   */
  public function accountSummary(): array;

  /**
   * Projects one authorized workforce relationship.
   */
  public function relationship(string $id): array;

}
