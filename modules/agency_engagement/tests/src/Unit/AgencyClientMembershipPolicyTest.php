<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementActionPolicy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Membership grant is provisioned and does not mint staff or extra objects.
 *
 * Host adapter A12 pins the OIDC ceremony separately.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyClientMembershipPolicyTest extends EngagementDenyTestCase {

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

}
