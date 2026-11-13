<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Coordinator read of one engagement and its assignments.
 *
 * Scaffolding refuses. Slice 4 G1 is open.
 *
 * @todo S4 G1 D3 Authority rows are not represented.
 * @todo S4 G1 D5 Client membership is not represented.
 * @todo S4 G1 D8 Feed audiences are not represented.
 */
interface EngagementAssignmentReaderInterface {

  /**
   * Reads one engagement and its assignments.
   *
   * @param string $engagement_id
   *   Synthetic engagement identifier. Not an entity load.
   */
  public function readEngagementAssignments(string $engagement_id): void;

}
