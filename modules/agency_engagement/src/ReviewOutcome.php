<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Result of one storage-free review-path check.
 */
final class ReviewOutcome {

  public const APPROVED = 'approved';
  public const SELF_APPROVAL = 'self_approval';
  public const MISSING_ASSIGNMENT = 'missing_assignment';
  public const MISSING_PERMISSION = 'missing_permission';
  public const ALREADY_APPROVED = 'already_approved';
  public const REVISION_MISMATCH = 'revision_mismatch';

  public function __construct(
    public readonly string $submissionId,
    public readonly bool $allowed,
    public readonly string $reason,
  ) {}

  /**
   * Builds an allowed outcome.
   */
  public static function approved(string $submission_id): self {
    return new self($submission_id, TRUE, self::APPROVED);
  }

  /**
   * Builds a refused outcome.
   */
  public static function refused(string $submission_id, string $reason): self {
    return new self($submission_id, FALSE, $reason);
  }

}
