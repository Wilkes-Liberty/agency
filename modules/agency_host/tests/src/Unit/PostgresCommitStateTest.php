<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\Core\Database\Connection;
use Drupal\pgsql\Driver\Database\pgsql\TransactionManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Uncertain client status cannot authorize clearing the transaction stack.
 */
#[Group('agency_host')]
final class PostgresCommitStateTest extends UnitTestCase {

  /**
   * Both an active transaction and an unreadable status remain rollbackable.
   */
  #[DataProvider('uncertainStates')]
  public function testUncertainStatusPreservesRollback(bool $unreadable): void {
    $client = $this->createMock(\PDO::class);
    $client->expects($this->once())->method('beginTransaction')->willReturn(TRUE);
    $original = new \PDOException('Synthetic commit failure');
    $client->expects($this->once())->method('commit')->willThrowException($original);
    $probe = $client->expects($this->once())->method('inTransaction');
    if ($unreadable) {
      $probe->willThrowException(new \PDOException('Synthetic status failure'));
    }
    else {
      $probe->willReturn(TRUE);
    }
    $client->expects($this->once())->method('rollBack')->willReturn(TRUE);
    $connection = $this->createMock(Connection::class);
    $connection->method('getClientConnection')->willReturn($client);
    $manager = new TransactionManager($connection);
    $connection->method('transactionManager')->willReturn($manager);
    $transaction = $manager->push();
    $callbacks = [];
    $manager->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
      $callbacks[] = $success;
    });
    $caught = NULL;
    try {
      $transaction->commitOrRelease();
    }
    catch (\Throwable $error) {
      $caught = $error;
    }
    $this->assertSame($original, $caught);
    $this->assertTrue($manager->inTransaction());
    $this->assertSame(1, $manager->stackDepth());
    $this->assertSame([], $callbacks);
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([FALSE], $callbacks);
    $this->assertSame(0, $manager->stackDepth());
  }

  /**
   * Neither state proves that PostgreSQL ended the transaction.
   */
  public static function uncertainStates(): array {
    return [[FALSE], [TRUE]];
  }

}
