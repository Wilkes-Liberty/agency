<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Loads process-scoped origin approvals only for a local-root CLI invocation.
 */
final class CleanOriginEnvironment {

  /**
   * Returns explicit disabled defaults on any missing or invalid input.
   */
  public static function settings(): array {
    $empty = [
      'agency_host_origin_receipt_file' => '',
      'agency_host_origin_approval' => [],
    ];
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
      return $empty;
    }
    $path = getenv('DRUPAL_AGENCY_ORIGIN_RECEIPT_FILE');
    $json = getenv('DRUPAL_AGENCY_ORIGIN_APPROVAL_JSON');
    if (!is_string($path) || !str_starts_with($path, '/') || strlen($path) > 4096
      || preg_match('/[\x00-\x20\x7f]/', $path) === 1
      || !is_string($json) || strlen($json) > 4096) {
      return $empty;
    }
    try {
      $approval = json_decode($json, TRUE, 8, JSON_THROW_ON_ERROR);
      if (!is_array($approval)) {
        return $empty;
      }
      $required = ['receipt_sha256', 'target_sha256', 'execution_go_sha256'];
      if (array_diff($required, array_keys($approval))
        || array_diff(array_keys($approval), [...$required, 'plan_sha256'])) {
        return $empty;
      }
      foreach ($approval as $digest) {
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
          return $empty;
        }
      }
      return [
        'agency_host_origin_receipt_file' => $path,
        'agency_host_origin_approval' => $approval,
      ];
    }
    catch (\Throwable) {
      return $empty;
    }
  }

}
