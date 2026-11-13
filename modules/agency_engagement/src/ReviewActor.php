<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Caller-supplied standing for a review-path check.
 *
 * Assignment and review permission are independent booleans. They are
 * not looked up. A Discovery entitlement is not an input.
 */
final class ReviewActor {

  public function __construct(
    public readonly string $actorId,
    public readonly bool $hasAssignment,
    public readonly bool $hasReviewPermission,
  ) {
    if ($actorId === '' || str_contains($actorId, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
  }

}
