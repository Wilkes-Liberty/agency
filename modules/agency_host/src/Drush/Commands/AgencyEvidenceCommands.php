<?php

declare(strict_types=1);

namespace Drupal\agency_host\Drush\Commands;

use Drupal\agency_host\EvidenceWorker;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Trusted scheduled maintenance; no HTTP route or MCP tool.
 */
final class AgencyEvidenceCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(private readonly EvidenceWorker $worker) {
    parent::__construct();
  }

  /**
   * Drains a bounded batch and reports evidence health and alert delivery.
   */
  #[CLI\Command(name: 'agency:evidence-run')]
  public function run(): int {
    $report = $this->worker->run();
    $this->output()->writeln(json_encode($report, JSON_THROW_ON_ERROR));
    return $report['successful'] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}
