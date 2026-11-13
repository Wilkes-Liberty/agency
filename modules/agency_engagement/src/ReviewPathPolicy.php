<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free 2026-09-19 review-path rule restated as Slice 4 G1 D7.
 *
 * Inputs are plain value objects with synthetic ids. No entity loads.
 */
final class ReviewPathPolicy {

  /**
   * Inspects one submission. Does not persist and does not settle.
   */
  public function approve(
    ReviewActor $actor,
    TimeSubmissionReview $submission,
  ): ReviewOutcome {
    return $this->inspect($actor, $submission);
  }

  /**
   * Inspects every item. Returns all-approved or the conflict list.
   *
   * @param \Drupal\agency_engagement\ReviewActor $actor
   *   Caller-supplied reviewer standing.
   * @param list<TimeSubmissionReview> $submissions
   *   Synthetic submissions inspected together.
   *
   * @return \Drupal\agency_engagement\BulkReviewOutcome
   *   All-approved, or the conflict list with no partial success.
   */
  public function approveBulk(
    ReviewActor $actor,
    array $submissions,
  ): BulkReviewOutcome {
    $conflicts = [];
    foreach ($submissions as $submission) {
      $outcome = $this->inspect($actor, $submission);
      if (!$outcome->allowed) {
        $conflicts[] = $outcome;
      }
    }
    if ($conflicts !== []) {
      return BulkReviewOutcome::conflicts($conflicts);
    }
    return BulkReviewOutcome::allApproved();
  }

  /**
   * Applies each confirmed D7 check. First failure wins.
   */
  private function inspect(
    ReviewActor $actor,
    TimeSubmissionReview $submission,
  ): ReviewOutcome {
    $id = $submission->submissionId;
    if ($this->isSelfApproval($actor, $submission)) {
      return ReviewOutcome::refused($id, ReviewOutcome::SELF_APPROVAL);
    }
    if (!$this->hasAssignment($actor)) {
      return ReviewOutcome::refused($id, ReviewOutcome::MISSING_ASSIGNMENT);
    }
    if (!$this->hasReviewPermission($actor)) {
      return ReviewOutcome::refused($id, ReviewOutcome::MISSING_PERMISSION);
    }
    if ($this->amendsApproved($submission)) {
      return ReviewOutcome::refused($id, ReviewOutcome::ALREADY_APPROVED);
    }
    if (!$this->pinnedRevisionsMatch($submission)) {
      return ReviewOutcome::refused($id, ReviewOutcome::REVISION_MISMATCH);
    }
    return ReviewOutcome::approved($id);
  }

  /**
   * Deny self-approval.
   */
  private function isSelfApproval(
    ReviewActor $actor,
    TimeSubmissionReview $submission,
  ): bool {
    return $actor->actorId === $submission->submitterId;
  }

  /**
   * Reviewer assignment is required and is not implied by permission.
   */
  private function hasAssignment(ReviewActor $actor): bool {
    return $actor->hasAssignment;
  }

  /**
   * Explicit review permission is required and is not implied by assignment.
   */
  private function hasReviewPermission(ReviewActor $actor): bool {
    return $actor->hasReviewPermission;
  }

  /**
   * Refuse amending an already approved submission.
   */
  private function amendsApproved(TimeSubmissionReview $submission): bool {
    return $submission->alreadyApproved;
  }

  /**
   * Pinned entry revision ids must match the inspected set exactly.
   */
  private function pinnedRevisionsMatch(
    TimeSubmissionReview $submission,
  ): bool {
    $pinned = $submission->pinnedRevisions;
    $inspected = $submission->inspectedRevisions;
    ksort($pinned);
    ksort($inspected);
    return $pinned === $inspected;
  }

}
