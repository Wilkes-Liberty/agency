<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementActionPolicy;
use Drupal\agency_engagement\ReviewOutcome;
use PHPUnit\Framework\Attributes\Group;

/**
 * A10: acceptTimeSubmission and issueStatement do not call the payment engine.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyStatementPaymentBoundaryTest extends EngagementDenyTestCase {

  /**
   * Issue statement succeeds and does not call the payment engine.
   */
  public function testIssueStatementDoesNotCallPaymentEngine(): void {
    $decision = $this->policy()->issueStatement($this->actorA(), 'tenant-a');
    $this->assertProvisionedAbsent(
      $decision,
      [EngagementActionPolicy::PAYMENT_ENGINE],
    );
  }

  /**
   * Accept time submission succeeds and does not call the payment engine.
   */
  public function testAcceptTimeSubmissionDoesNotCallPaymentEngine(): void {
    $decision = $this->policy()->acceptTimeSubmission(
      $this->actorA(),
      'tenant-a',
      FALSE,
      TRUE,
      FALSE,
    );
    $this->assertProvisionedAbsent(
      $decision,
      [EngagementActionPolicy::PAYMENT_ENGINE],
    );
    $this->assertNotSame(ReviewOutcome::SELF_APPROVAL, $decision->reason);
  }

}
