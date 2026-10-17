<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises a real failed COMMIT, its callbacks and connection reuse.
 */
#[Group('agency_host')]
#[RunTestsInSeparateProcesses]
final class PostgresCommitFailureTest extends KernelTestBase {

  /**
   * A deferred constraint ends the transaction without publishing its writes.
   */
  public function testDeferredCommitFailure(): void {
    $database = $this->container->get('database');
    $this->assertSame('pgsql', $database->driver());
    $database->query('CREATE TABLE {commit_failure_control} (id INT UNIQUE DEFERRABLE INITIALLY DEFERRED)');
    $database->insert('commit_failure_control')->fields(['id' => 1])->execute();
    $key = 'commit_failure_reader_' . bin2hex(random_bytes(8));
    Database::addConnectionInfo($key, 'default', $database->getConnectionOptions());
    $reader = Database::getConnection('default', $key);
    $callbacks = [];
    $transaction = $database->startTransaction();
    $database->transactionManager()->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
      $callbacks[] = $success;
    });
    $database->insert('commit_failure_control')->fields(['id' => 2])->execute();
    $database->insert('commit_failure_control')->fields(['id' => 1])->execute();
    $failure = NULL;
    try {
      $transaction->commitOrRelease();
    }
    catch (\PDOException $error) {
      $failure = $error;
    }
    $this->assertInstanceOf(\PDOException::class, $failure);
    $this->assertSame('23505', $failure->getCode());
    $this->assertFalse($database->getClientConnection()->inTransaction());
    $this->assertSame(0, $database->transactionManager()->stackDepth());
    // Exercise the same manager path used at shutdown before destruction.
    $database->transactionManager()->commitAll();
    unset($transaction);
    $this->assertSame([FALSE], $callbacks);
    $this->assertSame([1], array_map('intval', $reader->query('SELECT id FROM {commit_failure_control} ORDER BY id')->fetchCol()));

    // Destroy the failed root before reuse: callback state belongs to the
    // manager on Drupal 11.4. Normal commit and rollback must still work.
    foreach ([TRUE, FALSE] as $commit) {
      $transaction = $database->startTransaction();
      $database->transactionManager()->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
        $callbacks[] = $success;
      });
      $database->insert('commit_failure_control')->fields(['id' => $commit ? 2 : 3])->execute();
      $commit ? $transaction->commitOrRelease() : $transaction->rollBack();
      unset($transaction);
    }
    $this->assertSame([FALSE, TRUE, FALSE], $callbacks);
    $this->assertSame([1, 2], array_map('intval', $reader->query('SELECT id FROM {commit_failure_control} ORDER BY id')->fetchCol()));
    $database->transactionManager()->commitAll();
    $this->assertSame([FALSE, TRUE, FALSE], $callbacks);
    Database::removeConnection($key);
    $database->schema()->dropTable('commit_failure_control');
  }

}
