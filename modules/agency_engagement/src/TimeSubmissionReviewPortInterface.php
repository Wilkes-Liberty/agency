<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Time-submission review port.
 *
 * Scaffolding refuses. Review-path checks live on ReviewPathPolicy,
 * not on this port. Slice 4 G1 is open.
 *
 * @todo S4 G1 D4 Time-session revision policy is not assumed.
 * @todo S4 G1 D9 Public refusal strings remain open.
 */
interface TimeSubmissionReviewPortInterface {

  /**
   * Reviews one time submission.
   *
   * @param string $submission_id
   *   Synthetic submission identifier. Not an entity load.
   */
  public function reviewTimeSubmission(string $submission_id): void;

}
