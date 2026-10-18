<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Input for revokeStaffInvitation. Does not touch Discovery entitlements.
 */
final class RevokeStaffInvitation {

  /**
   * Workforce relationship UUID.
   */
  public readonly string $relationshipId;

  /**
   * Expected version; denial still applies when stale at expand time.
   */
  public readonly int $version;

  public function __construct(string $relationshipId, int $version) {
    $this->relationshipId = WorkforceInput::uuid($relationshipId);
    $this->version = WorkforceInput::version($version);
  }

  /**
   * Validates coordinator-supplied revocation input.
   */
  public static function fromArray(array $input): self {
    WorkforceInput::onlyKeys($input, ['relationship_id', 'version']);
    return new self(
      WorkforceInput::uuid($input['relationship_id'] ?? NULL),
      WorkforceInput::version($input['version'] ?? NULL),
    );
  }

}
