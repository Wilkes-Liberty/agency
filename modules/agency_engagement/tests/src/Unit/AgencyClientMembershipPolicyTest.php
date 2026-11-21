<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\ClientMembershipPolicy;
use Drupal\agency_engagement\EngagementActionPolicy;
use Drupal\agency_engagement\EngagementDecision;
use Drupal\agency_engagement\MembershipAttempt;
use PHPUnit\Framework\Attributes\Group;

/**
 * Membership policy: visitor roles only, privileged refuse, no revoke.
 *
 * Host adapter A12 pins the OIDC ceremony separately.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyClientMembershipPolicyTest extends EngagementDenyTestCase {

  /**
   * Synthetic group path to visitor role map. Host supplies live paths.
   */
  private const GROUP_MAP = [
    'clients-group' => 'client',
    'partners-group' => 'partner',
    'operators-group' => 'content_editor',
  ];

  /**
   * Membership grant does not mint staff roles or create objects.
   */
  public function testMembershipGrantDoesNotMintStaffRoles(): void {
    $decision = $this->policy()->grantMembership(
      $this->actorA(),
      'tenant-a',
      ['client', 'employee', 'contractor', 'content_editor'],
    );
    $this->assertProvisionedAbsent($decision, [
      ...EngagementActionPolicy::STAFF_ROLES,
      ...EngagementActionPolicy::MEMBERSHIP_CREATES,
    ]);
  }

  /**
   * Deny path 1: no visitor group claim, no portal session.
   */
  public function testMissingVisitorGroupIsRefused(): void {
    $decision = $this->membership()->preAuthorize(
      $this->attempt(['claimedGroups' => []]),
    );
    $this->assertSame(EngagementDecision::OUTCOME_REFUSED, $decision->outcome);
    $this->assertSame(
      ClientMembershipPolicy::REASON_MISSING_GROUP,
      $decision->reason,
    );
    $this->assertNotSame($this->identityDeny()['reason'], $decision->reason);
  }

  /**
   * A group name matches only as an exact configured path.
   */
  public function testNearMissGroupIsRefused(): void {
    foreach (['partners-group-acme', 'clients', 'Partners-group'] as $group) {
      $decision = $this->membership()->preAuthorize(
        $this->attempt(['claimedGroups' => [$group]]),
      );
      $this->assertSame(
        ClientMembershipPolicy::REASON_MISSING_GROUP,
        $decision->reason,
        $group . ' was accepted as a visitor group.',
      );
    }
  }

  /**
   * Staff OIDC clients are ignored by membership policy.
   */
  public function testNonPortalClientIgnored(): void {
    $decision = $this->membership()->preAuthorize(
      $this->attempt([
        'isPortalClient' => FALSE,
        'claimedGroups' => [],
      ]),
    );
    $this->assertSame(
      EngagementDecision::OUTCOME_PROVISIONED,
      $decision->outcome,
    );
  }

  /**
   * A privileged matched account never passes the portal client.
   */
  public function testPrivilegedAccountIsRefused(): void {
    $decision = $this->membership()->preAuthorize(
      $this->attempt([
        'accountExists' => TRUE,
        'accountId' => 12,
        'accountRoles' => ['content_editor'],
      ]),
    );
    $this->assertSame(
      ClientMembershipPolicy::REASON_PRIVILEGED,
      $decision->reason,
    );
    $this->assertNotSame($this->identityDeny()['reason'], $decision->reason);
  }

  /**
   * Uid 1 is privileged even with no roles.
   */
  public function testUid1IsPrivileged(): void {
    $decision = $this->membership()->preAuthorize(
      $this->attempt([
        'accountExists' => TRUE,
        'accountId' => 1,
        'accountRoles' => [],
      ]),
    );
    $this->assertSame(
      ClientMembershipPolicy::REASON_PRIVILEGED,
      $decision->reason,
    );
  }

  /**
   * A protected account name is privileged even with no roles.
   */
  public function testProtectedNameIsRefused(): void {
    $decision = $this->membership()->preAuthorize(
      $this->attempt([
        'accountExists' => TRUE,
        'accountId' => 9,
        'accountName' => 'service-account',
        'accountRoles' => [],
        'protectedAccountNames' => ['service-account'],
      ]),
    );
    $this->assertSame(
      ClientMembershipPolicy::REASON_PRIVILEGED,
      $decision->reason,
    );
  }

  /**
   * Visitor roles are granted; staff roles in the host map are ignored.
   */
  public function testVisitorRolesGrantedWithoutStaff(): void {
    $decision = $this->membership()->reconcileRoles(
      $this->attempt([
        'claimedGroups' => [
          'clients-group',
          'operators-group',
          'content_editor',
        ],
      ]),
    );
    $this->assertSame(['client'], $decision->payload['roles']);
    $this->assertProvisionedAbsent($decision, [
      ...EngagementActionPolicy::STAFF_ROLES,
      ...EngagementActionPolicy::MEMBERSHIP_CREATES,
    ]);
  }

  /**
   * Both visitor groups grant both roles and still exclude staff.
   */
  public function testBothVisitorGroupsStillExcludeStaffRoles(): void {
    $decision = $this->membership()->reconcileRoles(
      $this->attempt([
        'claimedGroups' => ['clients-group', 'partners-group'],
      ]),
    );
    $this->assertSame(['client', 'partner'], $decision->payload['roles']);
    $this->assertNotContains('content_editor', $decision->payload['roles']);
  }

  /**
   * Reconcile never removes a visitor role the claim no longer carries.
   */
  public function testPostAuthorizeNeverRevokes(): void {
    $decision = $this->membership()->reconcileRoles(
      $this->attempt([
        'accountRoles' => ['client', 'partner'],
        'claimedGroups' => ['partners-group'],
      ]),
    );
    $this->assertContains('client', $decision->payload['roles']);
    $this->assertContains('partner', $decision->payload['roles']);
  }

  /**
   * Privileged accounts are not granted visitor roles.
   */
  public function testStaffRoleInGroupMapIsIgnored(): void {
    $decision = $this->membership()->reconcileRoles(
      $this->attempt([
        'accountExists' => TRUE,
        'accountId' => 12,
        'accountRoles' => ['content_editor'],
        'claimedGroups' => ['partners-group', 'operators-group'],
      ]),
    );
    $this->assertSame([], $decision->payload['roles']);
    $this->assertContains(
      'content_editor',
      EngagementActionPolicy::STAFF_ROLES,
    );
    $this->assertContains('content_editor', $decision->absentRoles);
  }

  /**
   * Membership policy under test.
   */
  private function membership(): ClientMembershipPolicy {
    return new ClientMembershipPolicy();
  }

  /**
   * Builds one attempt with visitor-group defaults.
   *
   * @param array<string, mixed> $overrides
   *   Fields that differ from a fresh portal visitor.
   */
  private function attempt(array $overrides = []): MembershipAttempt {
    $defaults = [
      'isPortalClient' => TRUE,
      'claimedGroups' => ['clients-group'],
      'groupRoleMap' => self::GROUP_MAP,
      'allowedRoles' => ClientMembershipPolicy::VISITOR_ROLES,
      'accountRoles' => [],
      'accountId' => 0,
      'accountName' => '',
      'protectedAccountNames' => [],
      'sessionAuthenticated' => FALSE,
      'sessionPrivileged' => FALSE,
      'emailMatchesPrivileged' => FALSE,
      'accountExists' => FALSE,
    ];
    $values = $overrides + $defaults;
    return new MembershipAttempt(
      $values['isPortalClient'],
      $values['claimedGroups'],
      $values['groupRoleMap'],
      $values['allowedRoles'],
      $values['accountRoles'],
      $values['accountId'],
      $values['accountName'],
      $values['protectedAccountNames'],
      $values['sessionAuthenticated'],
      $values['sessionPrivileged'],
      $values['emailMatchesPrivileged'],
      $values['accountExists'],
    );
  }

}
