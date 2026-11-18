<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Read;

/**
 * Identifies a workforce person and its reviewed authority binding.
 */
final class WorkforcePersonBinding {

  public function __construct(
    public readonly string $personUuid,
    public readonly string $issuer,
    public readonly string $subject,
    public readonly string $tenant,
  ) {}

}
