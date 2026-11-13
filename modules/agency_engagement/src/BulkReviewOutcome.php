<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Bulk approval is all-approved or a conflict list, never partial.
 */
final class BulkReviewOutcome {

  /**
   * Constructs a bulk result that is never a mixed success.
   *
   * @param bool $allApproved
   *   TRUE only when every inspected item was approved.
   * @param list<ReviewOutcome> $conflicts
   *   Refused items. Empty only when every item is approved.
   */
  public function __construct(
    public readonly bool $allApproved,
    public readonly array $conflicts,
  ) {
    if ($allApproved && $conflicts !== []) {
      throw new \LogicException('Bulk approval cannot be partial.');
    }
    if (!$allApproved && $conflicts === []) {
      throw new \LogicException('A conflict list is required.');
    }
    foreach ($conflicts as $conflict) {
      if ($conflict->allowed) {
        throw new \LogicException('Bulk approval cannot be partial.');
      }
    }
  }

  /**
   * Every inspected submission was approved.
   */
  public static function allApproved(): self {
    return new self(TRUE, []);
  }

  /**
   * Returns only conflicts. Passing items are not reported as approved.
   *
   * @param list<ReviewOutcome> $conflicts
   *   One or more refused items.
   *
   * @return self
   *   Conflict-only bulk result.
   */
  public static function conflicts(array $conflicts): self {
    return new self(FALSE, $conflicts);
  }

}
