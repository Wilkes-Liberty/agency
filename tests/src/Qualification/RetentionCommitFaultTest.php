<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Qualification;

use Drupal\agency_staff\RetentionRemoval;
use Drupal\agency_staff\Storage\TransactionBoundary;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\agency\Kernel\RetentionRemovalTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Qualifies a PostgreSQL host with the fix for core issue 3584465.
 *
 * @group agency_host_qualification
 */
#[Group('agency_host_qualification')]
#[RunTestsInSeparateProcesses]
final class RetentionCommitFaultTest extends RetentionRemovalTest {

  /**
   * A genuine deferred COMMIT rejection cannot publish successful removal.
   */
  public function testDeferredCommitRejection(): void {
    $database = $this->container->get('database');
    $this->assertSame('pgsql', $database->driver(), 'Run this explicit host qualification on PostgreSQL.');
    $record = $this->terminal();
    $prepared = $this->container->get('agency_staff.retention_removal')->prepare($record->uuid());
    $callbacks = [];
    $registered = FALSE;
    $real = $this->container->get('entity_type.manager');
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnCallback(static function (string $type) use ($real, $database, &$callbacks, &$registered) {
      if (!$registered) {
        $database->transactionManager()->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
          $callbacks[] = $success;
        });
        $registered = TRUE;
      }
      return $real->getStorage($type);
    });
    $service = new RetentionRemoval(
      $this->container->get('agency_staff.store'),
      $this->container->get('agency_staff.access'),
      $this->container->get('agency_staff.retention_policy'),
      $entities,
      $this->container->get('agency_staff.commands'),
      $this->container->get('agency_staff.outbox'),
      $this->container->get('datetime.time'),
      $this->container->get('settings'),
    );
    $function = 'retention_commit_failure_' . bin2hex(random_bytes(8));
    $database->query("CREATE FUNCTION $function() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic retention commit failure''; END'", [], ['allow_delimiter_in_query' => TRUE]);
    $database->query("CREATE CONSTRAINT TRIGGER retention_commit_failure AFTER INSERT ON {agency_staff_outbox} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation = 'removeExpiredDiscovery') EXECUTE FUNCTION $function()");
    try {
      $before = $this->businessHash();
      $this->assertSame('uncertain', $service->remove($record->uuid(), $prepared['plan_sha256'], 'deferred-fault')['outcome']);
      $this->assertSame([FALSE], $callbacks);
      $this->assertFalse($database->inTransaction());
      $this->assertSame($before, $this->businessHash());
      $this->assertSame(0, $this->countRows('agency_staff_intent'));
      $this->assertSame(0, $this->countRows('agency_staff_outbox', ['operation' => 'removeExpiredDiscovery']));
      // A new root transaction must commit normally without retrying the ended
      // transaction, including when PHPUnit shuts this process down.
      $transaction = $database->startTransaction();
      $database->transactionManager()->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
        $callbacks[] = $success;
      });
      TransactionBoundary::commit($transaction);
      $transaction = NULL;
      $this->assertSame([FALSE, TRUE], $callbacks);
    }
    finally {
      $database->query('DROP TRIGGER IF EXISTS retention_commit_failure ON {agency_staff_outbox}');
      $database->query("DROP FUNCTION $function()");
    }
  }

}
