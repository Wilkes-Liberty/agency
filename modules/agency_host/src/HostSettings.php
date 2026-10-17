<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\Core\Site\Settings;

/**
 * Reads agency_host settings keys, then the former chronicle_agency names.
 */
final class HostSettings {

  /**
   * Prefers the current host key and keeps the previous name readable.
   */
  public static function get(Settings $settings, string $suffix, mixed $default = NULL): mixed {
    foreach (['agency_host_' . $suffix, 'chronicle_agency_' . $suffix] as $name) {
      $value = $settings->get($name, NULL);
      if ($value !== NULL && $value !== '' && $value !== []) {
        return $value;
      }
    }
    return $default;
  }

  /**
   * Reads the process-wide Settings bag using the same dual-name rule.
   */
  public static function current(string $suffix, mixed $default = NULL): mixed {
    return self::get(new Settings(Settings::getAll()), $suffix, $default);
  }

}
