<?php

declare(strict_types=1);

namespace Drupal\agency_host\Drush\Commands;

use Drupal\agency_host\HostSettings;
use Drupal\agency_host\Identity\CleanOriginPlan;
use Drupal\agency_host\Identity\CleanOriginQualifier;
use Drupal\Core\Site\Settings;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Deployment-owner origin commands; never exposed through routes or MCP.
 */
final class AgencyOriginCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly CleanOriginPlan $plan,
    private readonly Settings $settings,
    private readonly CleanOriginQualifier $qualifier,
  ) {
    parent::__construct();
  }

  /**
   * Reads the protected receipt and prints only a bounded digest report.
   */
  #[CLI\Command(name: 'agency:origin-plan')]
  public function plan(): int {
    try {
      $report = $this->plan->report($this->receipt());
      $this->output()->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }
    catch (\Throwable) {
      $this->logger()->error('Clean-origin plan is unavailable; no receipt or configuration values are printed.');
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Consumes the independently approved plan without activating participant entry.
   */
  #[CLI\Command(name: 'agency:origin-qualify')]
  public function qualify(): int {
    try {
      $report = $this->qualifier->qualify($this->receipt());
      $this->output()->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
      return in_array($report['outcome'], ['completed', 'already_completed'], TRUE)
        && ($report['current_qualification_ready'] ?? FALSE) === TRUE
        ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }
    catch (\Throwable) {
      $this->logger()->error('Clean-origin qualification is unavailable; activation was not performed.');
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Both commands require the same local-root, protected-file boundary.
   */
  private function receipt(): string {
    $path = HostSettings::get($this->settings, 'origin_receipt_file');
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
      || !is_string($path) || !str_starts_with($path, '/')
      || realpath($path) !== $path || !is_file($path) || !is_readable($path)
      || fileowner($path) !== 0 || (fileperms($path) & 0077) !== 0
      || filesize($path) > 16384) {
      throw new \UnexpectedValueException();
    }
    $bytes = file_get_contents($path, FALSE, NULL, 0, 16385);
    if ($bytes === FALSE) {
      throw new \UnexpectedValueException();
    }
    return $bytes;
  }

}
