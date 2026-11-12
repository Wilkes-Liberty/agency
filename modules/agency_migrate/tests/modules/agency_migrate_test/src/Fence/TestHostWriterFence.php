<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Fence;

use Drupal\agency_migrate\HostWriterFenceInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Test double for transition/replay mechanics, never proof of host coverage.
 *
 * This explicitly does not fence source/target tables. Only the operational
 * authority row serialization is exercised by the independent-process tests.
 */
final class TestHostWriterFence implements HostWriterFenceInterface {

  /**
   * In-process fault latch; this does not certify concurrent host exclusion.
   */
  private bool $journalInjected = FALSE;

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assertAvailable(string $domain): void {}

  /**
   * {@inheritdoc}
   */
  public function acquire(string $domain): void {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('A caller transaction is required.');
    }
    $row = $this->state->get('agency_migrate_test.fence_journal_row');
    if (is_array($row)) {
      $this->database->insert('agency_migrate_write_log')->fields([
        'domain' => $domain,
        'entity_type' => 'agency_migrate_agency_item',
        'entity_id' => $row['id'],
        'entity_uuid' => $row['uuid'],
        'recorded' => 1,
        'replayed' => NULL,
      ])->execute();
      $this->journalInjected = TRUE;
    }
  }

  /**
   * Whether the explicit in-process fault reached fence acquisition.
   */
  public function journalWasInjected(): bool {
    return $this->journalInjected;
  }

}
