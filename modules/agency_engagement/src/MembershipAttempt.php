<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free portal membership attempt.
 *
 * Group paths and allowed roles come from the host adapter. This object
 * does not load users, mutate accounts or run the OIDC ceremony.
 */
final class MembershipAttempt {

  /**
   * Constructs one attempt.
   *
   * @param bool $isPortalClient
   *   FALSE when the ceremony belongs to a staff client.
   * @param list<string> $claimedGroups
   *   Group paths from the token. Exact full paths only.
   * @param array<string, string> $groupRoleMap
   *   Configured group path to role id. Host supplies the paths.
   * @param list<string> $allowedRoles
   *   Roles that are not privileged on a portal account.
   * @param list<string> $accountRoles
   *   Roles already on the matched account. Empty when none.
   * @param int $accountId
   *   Matched account id, or 0 when none.
   * @param string $accountName
   *   Matched account name, or empty when none.
   * @param list<string> $protectedAccountNames
   *   Names that are privileged even with no roles.
   * @param bool $sessionAuthenticated
   *   TRUE on the connect path when a session already exists.
   * @param bool $sessionPrivileged
   *   TRUE when that session is privileged.
   * @param bool $emailMatchesPrivileged
   *   TRUE when email would link a privileged account.
   * @param bool $accountExists
   *   TRUE when a sub-matched or existing account is in play.
   */
  public function __construct(
    public readonly bool $isPortalClient,
    public readonly array $claimedGroups,
    public readonly array $groupRoleMap,
    public readonly array $allowedRoles,
    public readonly array $accountRoles,
    public readonly int $accountId,
    public readonly string $accountName,
    public readonly array $protectedAccountNames,
    public readonly bool $sessionAuthenticated,
    public readonly bool $sessionPrivileged,
    public readonly bool $emailMatchesPrivileged,
    public readonly bool $accountExists,
  ) {
    if (str_contains($this->accountName, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
    foreach ([
      $this->claimedGroups,
      $this->allowedRoles,
      $this->accountRoles,
      $this->protectedAccountNames,
    ] as $list) {
      foreach ($list as $item) {
        if (str_contains($item, "\0")) {
          throw new \InvalidArgumentException('invalid');
        }
      }
    }
    foreach ($this->groupRoleMap as $group => $role) {
      if (str_contains($group, "\0") || str_contains($role, "\0")) {
        throw new \InvalidArgumentException('invalid');
      }
    }
  }

}
