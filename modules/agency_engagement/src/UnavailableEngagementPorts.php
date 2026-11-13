<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

use Drupal\agency_engagement\Exception\EngagementRefusal;

/**
 * Default ports deny without creating records or widening authority.
 */
final class UnavailableEngagementPorts implements
  EngagementAssignmentReaderInterface,
  TimeSubmissionReviewPortInterface,
  StatementSnapshotReaderInterface {

  /**
   * {@inheritdoc}
   */
  public function readEngagementAssignments(string $engagement_id): void {
    throw new EngagementRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function reviewTimeSubmission(string $submission_id): void {
    throw new EngagementRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function readStatementSnapshot(string $snapshot_id): void {
    throw new EngagementRefusal('unavailable');
  }

}
