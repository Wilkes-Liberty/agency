<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\BulkReviewOutcome;
use Drupal\agency_engagement\ReviewActor;
use Drupal\agency_engagement\ReviewOutcome;
use Drupal\agency_engagement\ReviewPathPolicy;
use Drupal\agency_engagement\TimeSubmissionReview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * One test per confirmed 2026-09-19 review-path rule (Slice 4 G1 D7).
 *
 * @group agency
 */
#[Group('agency')]
final class ReviewPathPolicyTest extends TestCase {

  /**
   * Policy under test.
   */
  private ReviewPathPolicy $policy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->policy = new ReviewPathPolicy();
  }

  /**
   * Deny self-approval.
   */
  public function testSelfApprovalIsRefused(): void {
    $actor = $this->reviewer('actor-1');
    $submission = $this->submission('actor-1');
    $outcome = $this->policy->approve($actor, $submission);
    $this->assertFalse($outcome->allowed);
    $this->assertSame(ReviewOutcome::SELF_APPROVAL, $outcome->reason);
  }

  /**
   * Reviewer assignment and permission are separate required booleans.
   *
   * @dataProvider missingReviewStanding
   */
  #[DataProvider('missingReviewStanding')]
  public function testReviewRequiresAssignmentAndPermission(
    bool $has_assignment,
    bool $has_permission,
    string $reason,
  ): void {
    $actor = new ReviewActor(
      'reviewer-1',
      $has_assignment,
      $has_permission,
    );
    $submission = $this->submission('actor-1');
    $outcome = $this->policy->approve($actor, $submission);
    $this->assertFalse($outcome->allowed);
    $this->assertSame($reason, $outcome->reason);
  }

  /**
   * Standing combinations that must not review.
   */
  public static function missingReviewStanding(): array {
    return [
      'assignment without permission' => [
        TRUE,
        FALSE,
        ReviewOutcome::MISSING_PERMISSION,
      ],
      'permission without assignment' => [
        FALSE,
        TRUE,
        ReviewOutcome::MISSING_ASSIGNMENT,
      ],
      'neither assignment nor permission' => [
        FALSE,
        FALSE,
        ReviewOutcome::MISSING_ASSIGNMENT,
      ],
    ];
  }

  /**
   * Refuse amending an already approved submission.
   */
  public function testAmendingApprovedSubmissionIsRefused(): void {
    $submission = $this->submission('actor-1', TRUE);
    $outcome = $this->policy->approve(
      $this->reviewer('reviewer-1'),
      $submission,
    );
    $this->assertFalse($outcome->allowed);
    $this->assertSame(ReviewOutcome::ALREADY_APPROVED, $outcome->reason);
  }

  /**
   * Pinned entry revision ids must match the inspected set exactly.
   */
  public function testPinnedRevisionIdsMustMatchExactly(): void {
    $submission = new TimeSubmissionReview(
      'submission-1',
      'actor-1',
      FALSE,
      ['entry-1' => 'rev-1', 'entry-2' => 'rev-2'],
      ['entry-1' => 'rev-1', 'entry-2' => 'rev-9'],
    );
    $outcome = $this->policy->approve(
      $this->reviewer('reviewer-1'),
      $submission,
    );
    $this->assertFalse($outcome->allowed);
    $this->assertSame(ReviewOutcome::REVISION_MISMATCH, $outcome->reason);
  }

  /**
   * Bulk approval is all-approved or a conflict list, never partial.
   */
  public function testBulkApprovalIsAllOrConflictNeverPartial(): void {
    $actor = $this->reviewer('reviewer-1');
    $valid = $this->submission('actor-1');
    $conflict = $this->submission('actor-1', TRUE);
    $mixed = $this->policy->approveBulk($actor, [$valid, $conflict]);
    $this->assertFalse($mixed->allApproved);
    $this->assertCount(1, $mixed->conflicts);
    $this->assertSame(
      ReviewOutcome::ALREADY_APPROVED,
      $mixed->conflicts[0]->reason,
    );
    foreach ($mixed->conflicts as $item) {
      $this->assertFalse($item->allowed);
    }
    $second = $this->submission('actor-2');
    $all = $this->policy->approveBulk($actor, [$valid, $second]);
    $this->assertTrue($all->allApproved);
    $this->assertSame([], $all->conflicts);
    $this->expectException(\LogicException::class);
    new BulkReviewOutcome(TRUE, [
      ReviewOutcome::refused('submission-x', ReviewOutcome::SELF_APPROVAL),
    ]);
  }

  /**
   * An assigned reviewer with explicit permission may approve.
   */
  public function testAssignedReviewerWithPermissionCanApprove(): void {
    $outcome = $this->policy->approve(
      $this->reviewer('reviewer-1'),
      $this->submission('actor-1'),
    );
    $this->assertTrue($outcome->allowed);
    $this->assertSame(ReviewOutcome::APPROVED, $outcome->reason);
  }

  /**
   * ReviewActor does not take a Discovery entitlement.
   */
  public function testReviewActorDoesNotAcceptEntitlement(): void {
    $reflection = new \ReflectionClass(ReviewActor::class);
    $constructor = $reflection->getConstructor();
    $this->assertNotNull($constructor);
    $names = [];
    foreach ($constructor->getParameters() as $parameter) {
      $names[] = $parameter->getName();
    }
    $this->assertSame(
      ['actorId', 'hasAssignment', 'hasReviewPermission'],
      $names,
    );
  }

  /**
   * Assigned reviewer with permission and matching pins.
   */
  private function reviewer(string $actor_id): ReviewActor {
    return new ReviewActor($actor_id, TRUE, TRUE);
  }

  /**
   * Synthetic submission with matching pins.
   */
  private function submission(
    string $submitter_id,
    bool $already_approved = FALSE,
  ): TimeSubmissionReview {
    return new TimeSubmissionReview(
      'submission-' . $submitter_id,
      $submitter_id,
      $already_approved,
      ['entry-1' => 'rev-1'],
      ['entry-1' => 'rev-1'],
    );
  }

}
