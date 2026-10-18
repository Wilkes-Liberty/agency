<?php

declare(strict_types=1);

namespace Drupal\agency_host\Drush\Commands;

use Drupal\agency_host\RuntimeReadiness;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Operator CLI diagnostics; no public route or MCP tool.
 */
final class AgencyReadinessCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(private readonly RuntimeReadiness $readiness) {
    parent::__construct();
  }

  /**
   * Checks configuration without external HTTP calls or Agency state mutations.
   */
  #[CLI\Command(name: 'agency:readiness')]
  public function readiness(): int {
    try {
      $report = $this->readiness->report();
      $this->output()->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
      return $report['configuration_ready'] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }
    catch (\Throwable) {
      $this->logger()->error('Agency readiness is unavailable; no configuration values are printed.');
      return self::EXIT_FAILURE;
    }
  }

}
