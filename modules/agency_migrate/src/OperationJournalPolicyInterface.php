<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

/**
 * Host declaration of equivalent protection for a target's complete journal.
 */
interface OperationJournalPolicyInterface {

  /**
   * Declares approved storage and markings for one exact target scope.
   *
   * The host must verify journal access, recovery copies and retention against
   * the target's controls for every historical value and derived copy. The
   * contract ID must change when the stored layout or these protections change.
   * Return NULL when any part of that assurance is unavailable. Implementations
   * must be read-only, and must use the entity type ID for an unbundled target.
   *
   * @return array|null
   *   A declaration with contract_id, access_equivalent, recovery_equivalent,
   *   retention_equivalent, and markings (field name to a stored property name
   *   to its marking). Contract IDs are nonsecret versioned identifiers.
   */
  public function declaration(string $domain, string $entityType, string $bundle): ?array;

}
