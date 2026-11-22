<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementDecision;
use PHPUnit\Framework\Attributes\Group;

/**
 * A9: foreign-entry submit keeps the host time door.
 *
 * Person-record identity deny is Discovery not_found.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyTimeForeignEntrySubmitTest extends EngagementDenyTestCase {

  /**
   * Submit time refuses foreign entries with the host time-door string.
   */
  public function testSubmitTimeRefusesForeignEntriesWithHostTimeDoor(): void {
    $decision = $this->policy()->submitTime(
      $this->actorA(),
      'tenant-a',
      ['actor-a', 'actor-b'],
    );
    $this->assertSame(EngagementDecision::OUTCOME_REFUSED, $decision->outcome);
    $this->assertNull($decision->reason);
    $this->assertSame(EngagementDecision::TIME_DOOR, $decision->timeDoor);
    $this->assertSame(['error' => 'denied'], $decision->timeDoor);
    $this->assertNotSame($this->identityDeny()['reason'], $decision->reason);
    $this->assertSame([], $decision->payload);
  }

  /**
   * Person-record identity deny uses Discovery not_found.
   */
  public function testPersonRecordReadUsesDiscoveryNotFound(): void {
    $decision = $this->policy()->readPersonRecord(
      $this->actorA(),
      'tenant-a',
      'actor-b',
    );
    $this->assertIdentityDeny($decision);
  }

  /**
   * Own entries on the same tenant submit.
   */
  public function testOwnEntriesSubmit(): void {
    $decision = $this->policy()->submitTime(
      $this->actorA(),
      'tenant-a',
      ['actor-a', 'actor-a'],
    );
    $this->assertProvisionedAbsent($decision, []);
  }

}
