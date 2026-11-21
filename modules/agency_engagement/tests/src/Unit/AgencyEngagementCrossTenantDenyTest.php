<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementDecision;
use PHPUnit\Framework\Attributes\Group;

/**
 * A1: foreign-tenant object deny is Discovery not_found; hatch stays distinct.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyEngagementCrossTenantDenyTest
  extends EngagementDenyTestCase {

  /**
   * A token whose tenant does not own the object returns not_found.
   */
  public function testForeignTenantObjectIsNotFound(): void {
    $decision = $this->policy()->readObject(
      $this->actorA(),
      'tenant-b',
      'engagement-1',
    );
    $this->assertIdentityDeny($decision);
  }

  /**
   * Missing object id is the same not_found body.
   */
  public function testMissingObjectIsNotFound(): void {
    $decision = $this->policy()->readObject($this->actorA(), 'tenant-a', '');
    $this->assertIdentityDeny($decision);
  }

  /**
   * Hatch missing tenant is not renamed to not_found.
   */
  public function testHatchMissingTenantIsNotRenamedToNotFound(): void {
    $decision = $this->policy()->readObject(
      $this->hatch(),
      'tenant-a',
      'engagement-1',
    );
    $this->assertSame(EngagementDecision::OUTCOME_REFUSED, $decision->outcome);
    $this->assertSame(
      EngagementDecision::REASON_MISSING_TENANT,
      $decision->reason,
    );
    $this->assertNotSame($this->identityDeny()['reason'], $decision->reason);
    $this->assertSame([], $decision->payload);
    $this->assertSame('denied_access', $decision->evidence[0]['operation']);
    $this->assertSame(
      EngagementDecision::REASON_MISSING_TENANT,
      $decision->evidence[0]['metadata']['reason'],
    );
  }

  /**
   * Same-tenant object read is provisioned.
   */
  public function testSameTenantObjectIsProvisioned(): void {
    $decision = $this->policy()->readObject(
      $this->actorA(),
      'tenant-a',
      'engagement-1',
    );
    $this->assertProvisionedAbsent($decision, []);
  }

}
