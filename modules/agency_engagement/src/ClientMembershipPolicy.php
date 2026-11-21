<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free client membership and eligibility policy.
 *
 * The host OIDC hook supplies group paths and account facts. This
 * policy never mints staff roles, never creates a user, profile,
 * engagement or Discovery relationship, and never revokes a visitor
 * role the claim no longer carries.
 */
final class ClientMembershipPolicy {

  /**
   * Visitor roles a membership grant may add.
   */
  public const VISITOR_ROLES = ['client', 'partner'];

  /**
   * Ceremony refuse when no visitor group is claimed.
   */
  public const REASON_MISSING_GROUP = 'missing_visitor_group';

  /**
   * Ceremony refuse when the account or session is privileged.
   */
  public const REASON_PRIVILEGED = 'privileged_account';

  /**
   * Visitor roles earned by exact group-path matches.
   *
   * Roles outside VISITOR_ROLES in the host map are ignored.
   *
   * @return list<string>
   *   Role ids in map order. Empty when no visitor group is claimed.
   */
  public function claimedRoles(MembershipAttempt $attempt): array {
    $roles = [];
    foreach ($attempt->groupRoleMap as $group => $role_id) {
      if (!in_array($role_id, self::VISITOR_ROLES, TRUE)) {
        continue;
      }
      if (in_array($group, $attempt->claimedGroups, TRUE)) {
        $roles[] = $role_id;
      }
    }
    return $roles;
  }

  /**
   * Decides whether a portal authorization may proceed.
   *
   * Staff clients are ignored. Missing visitor groups and privileged
   * accounts or sessions are refused with reasons that are not
   * not_found.
   */
  public function preAuthorize(MembershipAttempt $attempt): EngagementDecision {
    if (!$attempt->isPortalClient) {
      return EngagementDecision::provisioned();
    }
    if ($this->claimedRoles($attempt) === []) {
      return EngagementDecision::reviewRefused(self::REASON_MISSING_GROUP);
    }
    if ($attempt->sessionAuthenticated && $attempt->sessionPrivileged) {
      return EngagementDecision::reviewRefused(self::REASON_PRIVILEGED);
    }
    if ($attempt->accountExists && $this->isPrivileged($attempt)) {
      return EngagementDecision::reviewRefused(self::REASON_PRIVILEGED);
    }
    if (!$attempt->accountExists && $attempt->emailMatchesPrivileged) {
      return EngagementDecision::reviewRefused(self::REASON_PRIVILEGED);
    }
    return EngagementDecision::provisioned([
      ...EngagementActionPolicy::STAFF_ROLES,
      ...EngagementActionPolicy::MEMBERSHIP_CREATES,
    ]);
  }

  /**
   * Visitor roles the attempt would add. Privileged accounts stay empty.
   *
   * Existing visitor roles are kept even when the claim omits them.
   * Staff roles in the host map are never granted.
   */
  public function reconcileRoles(
    MembershipAttempt $attempt,
  ): EngagementDecision {
    $absent = [
      ...EngagementActionPolicy::STAFF_ROLES,
      ...EngagementActionPolicy::MEMBERSHIP_CREATES,
    ];
    if (!$attempt->isPortalClient) {
      return EngagementDecision::granted([], $absent);
    }
    if ($this->isPrivileged($attempt)) {
      return EngagementDecision::granted([], $absent);
    }
    $kept = array_values(array_intersect(
      $attempt->accountRoles,
      self::VISITOR_ROLES,
    ));
    $roles = array_values(array_unique([
      ...$kept,
      ...$this->claimedRoles($attempt),
    ]));
    return EngagementDecision::granted($roles, $absent);
  }

  /**
   * Privileged means uid 1, a role outside the allowlist, or a protected name.
   */
  private function isPrivileged(MembershipAttempt $attempt): bool {
    if ($attempt->accountId === 1) {
      return TRUE;
    }
    foreach ($attempt->accountRoles as $role_id) {
      if (!in_array($role_id, $attempt->allowedRoles, TRUE)) {
        return TRUE;
      }
    }
    return in_array(
      $attempt->accountName,
      $attempt->protectedAccountNames,
      TRUE,
    );
  }

}
