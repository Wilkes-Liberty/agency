<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

/**
 * Registered workforce content entity type ids.
 */
final class WorkforceTypes {

  public const PROFILE_EVIDENCE = 'agency_profile_evidence';

  public const STAFF_ONBOARD = 'agency_staff_onboard';

  public const RELATIONSHIP = 'agency_workforce';

  public const ENTITLEMENT = 'agency_workforce_entitlement';

  /**
   * Entity type ids installed by this module.
   */
  public const ALL = [
    self::PROFILE_EVIDENCE,
    self::STAFF_ONBOARD,
    self::RELATIONSHIP,
    self::ENTITLEMENT,
  ];

}
