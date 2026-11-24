<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Isolation rules for operating seats versus portal and human grants.
 *
 * Steward, intake, delivery and renewal are work-queue seats. They are
 * not portal roles. Unbounded owner scope and unknown seats fail
 * closed. Machine credentials never inherit human grants. Live user
 * provisioning stays host.
 */
final class SeatIsolation {

  /**
   * Whether an operating seat is a visitor-facing portal role.
   *
   * @param string $seat
   *   Candidate seat name.
   *
   * @return bool
   *   Always FALSE for known operating seats.
   *
   * @throws \InvalidArgumentException
   *   When the seat is not an operating seat.
   */
  public static function isPortalRole(string $seat): bool {
    if (!in_array($seat, OperatingRuns::SEATS, TRUE)) {
      throw new \InvalidArgumentException('Unknown operating seat.');
    }
    return FALSE;
  }

  /**
   * Whether a seat may be provisioned for the given explicit owners.
   *
   * @param string $seat
   *   Candidate seat name.
   * @param array<array-key, mixed> $owners
   *   Explicit owner identifiers.
   *
   * @return bool
   *   TRUE only for a known operating seat whose owners are a non-empty
   *   list of positive integer identifiers.
   */
  public static function ownersAllowed(string $seat, array $owners): bool {
    if (!in_array($seat, OperatingRuns::SEATS, TRUE)
      || $owners === []
      || !array_is_list($owners)) {
      return FALSE;
    }
    foreach ($owners as $owner) {
      // Zero is the anonymous account, never an explicit owner.
      if (!is_int($owner) || $owner < 1) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Machine credentials never inherit human grant names.
   *
   * @param list<string> $human_roles
   *   Role names held by a human principal.
   *
   * @return list<string>
   *   Always empty. Operating machines keep an isolated grant set.
   */
  public static function inheritedRoles(array $human_roles): array {
    // Operating machines keep an isolated grant set.
    return array_values(array_intersect($human_roles, []));
  }

}
