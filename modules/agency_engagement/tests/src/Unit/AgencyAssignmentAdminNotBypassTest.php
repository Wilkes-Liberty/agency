<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementDecision;
use Drupal\agency_engagement\ReviewActor;
use Drupal\agency_engagement\ReviewOutcome;
use Drupal\agency_engagement\ReviewPathPolicy;
use Drupal\agency_engagement\TimeSubmissionReview;
use PHPUnit\Framework\Attributes\Group;

/**
 * A11: administrator flag is not a bypass; self-approval is refused.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyAssignmentAdminNotBypassTest extends EngagementDenyTestCase {

  /**
   * Administrator flag without an accepted assignment is refused.
   */
  public function testAdministratorFlagIsNotABypass(): void {
    $decision = $this->policy()->acceptTimeSubmission(
      $this->actorA(),
      'tenant-a',
      FALSE,
      FALSE,
      TRUE,
    );
    $this->assertSame(EngagementDecision::OUTCOME_REFUSED, $decision->outcome);
    $this->assertSame(ReviewOutcome::MISSING_ASSIGNMENT, $decision->reason);
    $this->assertNotSame(
      EngagementDecision::REASON_NOT_FOUND,
      $decision->reason,
    );
  }

  /**
   * Self-approval of own submission is refused.
   */
  public function testSelfApprovalIsRefusedOnAcceptTimeSubmission(): void {
    $decision = $this->policy()->acceptTimeSubmission(
      $this->actorA(),
      'tenant-a',
      TRUE,
      TRUE,
      TRUE,
    );
    $this->assertSame(EngagementDecision::OUTCOME_REFUSED, $decision->outcome);
    $this->assertSame(ReviewOutcome::SELF_APPROVAL, $decision->reason);
    $review = (new ReviewPathPolicy())->approve(
      new ReviewActor('actor-a', TRUE, TRUE),
      new TimeSubmissionReview(
        'submission-1',
        'actor-a',
        FALSE,
        ['entry-1' => 'rev-1'],
        ['entry-1' => 'rev-1'],
      ),
    );
    $this->assertFalse($review->allowed);
    $this->assertSame(ReviewOutcome::SELF_APPROVAL, $review->reason);
  }

}
