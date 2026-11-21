<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementDecision;
use PHPUnit\Framework\Attributes\Group;

/**
 * Caller-facing consume-once replay and timeout. Cutover proofs stay G4.
 *
 * @group agency
 */
#[Group('agency')]
// phpcs:ignore Drupal.Files.LineLength.TooLong
final class AgencyEngagementConsumeOnceTimeoutTest extends EngagementDenyTestCase {

  /**
   * Replay of a consumed key is not_found and issues no second claim.
   */
  public function testConsumedKeyReplayIsNotFound(): void {
    $policy = $this->policy();
    $first = $policy->consume('week-1', 'digest-a', 10, 100);
    $this->assertProvisionedAbsent($first, []);
    $replay = $policy->consume('week-1', 'digest-a', 11, 100);
    $this->assertIdentityDeny($replay);
    $mismatch = $policy->consume('week-1', 'digest-b', 12, 100);
    $this->assertIdentityDeny($mismatch);
  }

  /**
   * An expired hold is not_found and issues no claim.
   */
  public function testExpiredHoldIsNotFoundWithoutClaim(): void {
    $policy = $this->policy();
    $policy->holdConsumeKey('week-held', 'digest-a', 10);
    $late = $policy->consume('week-held', 'digest-a', 11, 10);
    $this->assertIdentityDeny($late);
    $this->assertSame(
      EngagementDecision::REASON_NOT_FOUND,
      $late->reason,
    );
    $fresh = $policy->consume('week-held', 'digest-a', 12, 100);
    $this->assertProvisionedAbsent($fresh, []);
  }

}
