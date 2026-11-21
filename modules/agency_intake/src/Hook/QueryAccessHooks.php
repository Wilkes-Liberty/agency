<?php

declare(strict_types=1);

namespace Drupal\agency_intake\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Access-checked intake listings stay empty while rows exist.
 */
final class QueryAccessHooks {

  /**
   * Implements hook_query_TAG_alter() for agency_intake_submission_access.
   *
   * @param \Drupal\Core\Database\Query\AlterableInterface $query
   *   The tagged listing query.
   */
  #[Hook('query_agency_intake_submission_access_alter')]
  public function submissionAccess(AlterableInterface $query): void {
    if ($query instanceof ConditionInterface) {
      $query->alwaysFalse();
    }
  }

}
