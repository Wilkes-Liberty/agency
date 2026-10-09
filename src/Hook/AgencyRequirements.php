<?php

declare(strict_types=1);

namespace Drupal\agency\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Runtime requirements for Drupal versions that support class-based hooks.
 */
final class AgencyRequirements {

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    return agency_display_name_requirements();
  }

}
