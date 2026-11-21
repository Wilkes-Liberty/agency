<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementActionPolicy;
use PHPUnit\Framework\Attributes\Group;

/**
 * A2: engagement writes do not mint S2/S3 authority, and the reverse.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyEngagementDiscoveryCrmWallTest
  extends EngagementDenyTestCase {

  /**
   * offerAssignment succeeds and does not write Discovery or CRM.
   */
  public function testOfferAssignmentDoesNotWriteDiscoveryOrCrm(): void {
    $decision = $this->policy()->offerAssignment($this->actorA(), 'tenant-a');
    $this->assertProvisionedAbsent(
      $decision,
      EngagementActionPolicy::DISCOVERY_CRM_ROLES,
    );
  }

  /**
   * submitTime succeeds and does not write Discovery or CRM.
   */
  public function testSubmitTimeDoesNotWriteDiscoveryOrCrm(): void {
    $decision = $this->policy()->submitTime(
      $this->actorA(),
      'tenant-a',
      ['actor-a'],
    );
    $this->assertProvisionedAbsent(
      $decision,
      EngagementActionPolicy::DISCOVERY_CRM_ROLES,
    );
  }

  /**
   * issueStatement succeeds and does not write Discovery or CRM.
   */
  public function testIssueStatementDoesNotWriteDiscoveryOrCrm(): void {
    $decision = $this->policy()->issueStatement($this->actorA(), 'tenant-a');
    $this->assertProvisionedAbsent(
      $decision,
      EngagementActionPolicy::DISCOVERY_CRM_ROLES,
    );
  }

  /**
   * Discovery, workforce or CRM action does not create engagement records.
   */
  public function testDiscoveryActionDoesNotCreateEngagement(): void {
    $decision = $this->policy()->provisionFromDiscoveryOrCrm($this->actorA());
    $this->assertProvisionedAbsent(
      $decision,
      EngagementActionPolicy::ENGAGEMENT_RECORDS,
    );
  }

}
