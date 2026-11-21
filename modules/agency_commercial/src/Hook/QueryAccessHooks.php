<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Access-checked commercial listings stay empty while rows exist.
 */
final class QueryAccessHooks {

  /**
   * Implements hook_query_TAG_alter() for agency_opportunity_access.
   */
  #[Hook('query_agency_opportunity_access_alter')]
  public function opportunityAccess(AlterableInterface $query): void {
    $this->deny($query);
  }

  /**
   * Implements hook_query_TAG_alter() for agency_activity_access.
   */
  #[Hook('query_agency_activity_access_alter')]
  public function activityAccess(AlterableInterface $query): void {
    $this->deny($query);
  }

  /**
   * Marks an access-checked listing as matching no rows.
   *
   * @param \Drupal\Core\Database\Query\AlterableInterface $query
   *   The tagged listing query.
   */
  private function deny(AlterableInterface $query): void {
    if ($query instanceof ConditionInterface) {
      $query->alwaysFalse();
    }
  }

}
