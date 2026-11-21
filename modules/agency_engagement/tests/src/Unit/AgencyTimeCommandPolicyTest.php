<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementDecision;
use Drupal\agency_engagement\TimeCommandPolicy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Private time commands refuse foreign, visitor and reviewer-only reads.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyTimeCommandPolicyTest extends EngagementDenyTestCase {

  /**
   * Anonymous or unassigned callers cannot read private workspace time.
   */
  public function testAnonymousWorkspaceIsNotFound(): void {
    $policy = new TimeCommandPolicy();
    $this->assertIdentityDeny($policy->privateAccess([]));
  }

  /**
   * Assigned staff with permission may read; others are not_found.
   */
  public function testPrivateAccessIsOwnerAssigned(): void {
    $policy = new TimeCommandPolicy();
    $ok = $policy->privateAccess([
      'permission' => TRUE,
      'assigned' => TRUE,
    ]);
    $this->assertProvisionedAbsent($ok, []);
    $denied = $policy->privateAccess([
      'permission' => TRUE,
      'assigned' => FALSE,
    ]);
    $this->assertIdentityDeny($denied);
  }

  /**
   * Visitor or machine mixing cannot inherit staff authority.
   */
  public function testVisitorAndMachineRolesAreDenied(): void {
    $policy = new TimeCommandPolicy();
    foreach (['visitor_role', 'machine_role', 'blocked'] as $flag) {
      $decision = $policy->privateAccess([
        'permission' => TRUE,
        'assigned' => TRUE,
        $flag => TRUE,
      ]);
      $this->assertIdentityDeny($decision);
    }
  }

  /**
   * Reviewer-only assignment does not grant draft access.
   */
  public function testReviewerOnlyCannotReadDraft(): void {
    $policy = new TimeCommandPolicy();
    $this->assertIdentityDeny($policy->privateAccess([
      'permission' => TRUE,
      'assigned' => TRUE,
      'reviewer_only' => TRUE,
    ]));
  }

  /**
   * Profile access is required; a different uid cannot inherit the client.
   */
  public function testClientReferenceBoundary(): void {
    $policy = new TimeCommandPolicy();
    $denied = $policy->clientReference([
      'may_view_profile' => FALSE,
      'client_uid' => 4,
      'profile_uid' => 4,
    ]);
    $this->assertSame(EngagementDecision::OUTCOME_REFUSED, $denied->outcome);
    $this->assertSame('access_denied', $denied->reason);
    $this->assertNotSame(
      EngagementDecision::REASON_NOT_FOUND,
      $denied->reason,
    );
    $ok = $policy->clientReference([
      'may_view_profile' => TRUE,
      'client_uid' => 4,
      'profile_uid' => 4,
    ]);
    $this->assertProvisionedAbsent($ok, []);
    $this->expectException(\InvalidArgumentException::class);
    $policy->clientReference([
      'may_view_profile' => TRUE,
      'client_uid' => 9,
      'profile_uid' => 4,
    ]);
  }

  /**
   * Stale assignment revision cannot reactivate.
   */
  public function testAssignmentConflictUsesCurrentRevision(): void {
    $policy = new TimeCommandPolicy();
    $stale = $policy->assignmentConflict(1, 2, FALSE);
    $this->assertSame('conflict', $stale->reason);
    $this->assertProvisionedAbsent(
      $policy->assignmentConflict(2, 2, TRUE),
      [],
    );
  }

  /**
   * Private note and rate keys stay off command echoes.
   */
  public function testPrivateKeysStayOffCommands(): void {
    foreach (TimeCommandPolicy::PRIVATE_KEYS as $key) {
      $this->assertNotContains($key, [
        'id',
        'revision',
        'seconds',
        'active',
      ]);
    }
  }

}
