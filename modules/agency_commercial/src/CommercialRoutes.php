<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Route-name eligibility for commercial work, without a breadcrumb builder.
 *
 * Host wl_crm.*, entity.crm_* and view.crm_pipeline.* stay host. Agency
 * matches opportunity, activity and agency_commercial. prefixes only.
 */
final class CommercialRoutes {

  /**
   * Whether a route name is commercial.
   *
   * Eligibility is the route name only. Optional cache metadata records
   * the route context on both a hit and a miss.
   *
   * @param string $route_name
   *   Candidate route name.
   * @param object|null $cacheable_metadata
   *   Optional cache metadata. When supplied, records 'route'.
   *
   * @return bool
   *   TRUE only for commercial opportunity, activity or module routes.
   */
  public static function applies(
    string $route_name,
    ?object $cacheable_metadata = NULL,
  ): bool {
    if ($cacheable_metadata !== NULL
      && method_exists($cacheable_metadata, 'addCacheContexts')) {
      $cacheable_metadata->addCacheContexts(['route']);
    }
    return str_starts_with($route_name, 'entity.agency_opportunity.')
      || str_starts_with($route_name, 'entity.agency_activity.')
      || str_starts_with($route_name, 'agency_commercial.');
  }

}
