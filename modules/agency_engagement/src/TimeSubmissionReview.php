<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Synthetic time-submission facts for a storage-free review-path check.
 *
 * Revision maps are entry id to pinned or inspected revision id. This
 * object does not load entities and does not describe a time session.
 */
final class TimeSubmissionReview {

  /**
   * Constructs a synthetic submission review payload.
   *
   * @param string $submissionId
   *   Synthetic submission identifier.
   * @param string $submitterId
   *   Synthetic submitter identifier.
   * @param bool $alreadyApproved
   *   TRUE when the submission is already approved.
   * @param array<string, string> $pinnedRevisions
   *   Exact entry revision pins at submit.
   * @param array<string, string> $inspectedRevisions
   *   Entry revision ids the reviewer inspected.
   */
  public function __construct(
    public readonly string $submissionId,
    public readonly string $submitterId,
    public readonly bool $alreadyApproved,
    public readonly array $pinnedRevisions,
    public readonly array $inspectedRevisions,
  ) {
    if ($submissionId === '' || str_contains($submissionId, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
    if ($submitterId === '' || str_contains($submitterId, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
    self::revisionMap($pinnedRevisions);
    self::revisionMap($inspectedRevisions);
  }

  /**
   * Refuses empty or NUL revision-map entries.
   *
   * @param array<string, string> $map
   *   Entry id to revision id.
   */
  private static function revisionMap(array $map): void {
    foreach ($map as $entry_id => $revision_id) {
      if ($entry_id === '' || $revision_id === '') {
        throw new \InvalidArgumentException('invalid');
      }
      if (str_contains($entry_id, "\0") || str_contains($revision_id, "\0")) {
        throw new \InvalidArgumentException('invalid');
      }
    }
  }

}
