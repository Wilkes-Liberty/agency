<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\TransactionBoundary;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;

/**
 * Legacy writes cannot outlive their checked authority generation.
 *
 * Failure cases: autocommit, wrong connection, unknown/promoted authority,
 * missing storage, permission confusion and a transition racing root commit.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateLegacyWriterTest extends MigrateKernelTestBase {

  /**
   * A snapshot in autocommit cannot certify a later mutation.
   */
  public function testLegacyWriterRequiresExistingTransaction(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    $this->assertRefused(fn () => $authority->lockForLegacyWrite($this->container->get('database'), 'synthetic'), 'writer_transaction_required');
    $this->assertFalse($this->container->get('database')->inTransaction());
  }

  /**
   * A transaction on a different connection cannot retain the authority lock.
   */
  public function testLegacyWriterRejectsDifferentConnection(): void {
    $database = $this->container->get('database');
    Database::addConnectionInfo('legacy_other', 'default', $database->getConnectionOptions());
    $other = Database::getConnection('default', 'legacy_other');
    $transaction = $other->startTransaction();
    try {
      $this->assertRefused(fn () => $this->container->get('agency_migrate.authority')->lockForLegacyWrite($other, 'synthetic'), 'writer_connection_mismatch');
    }
    finally {
      $transaction->rollBack();
      Database::closeConnection('default', 'legacy_other');
    }
  }

  /**
   * Anonymous business writes need no authority-administration permission.
   */
  public function testLegacyWriterAllowsRegisteredGenerationWithoutPromotion(): void {
    $database = $this->container->get('database');
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    foreach (['legacy' => 1, 'shadow' => 2] as $state => $version) {
      if ($state === 'shadow') {
        $this->container->get('current_user')->setAccount($this->operator);
        $authority->transition('synthetic', 'shadow', 'begin shadow');
      }
      $before = $authority->status();
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
      $transaction = $database->startTransaction();
      try {
        $this->assertSame($version, $authority->lockForLegacyWrite($database, 'synthetic'));
        $this->assertSame($before, $authority->status());
        $this->assertRefused(fn () => $authority->transition('synthetic', 'agency', 'unauthorized promotion'), 'forbidden');
        $this->assertTrue($database->inTransaction());
      }
      finally {
        $transaction->rollBack();
      }
    }
    $this->assertSame(2, (int) $database->select('agency_migrate_authority_event', 'e')->countQuery()->execute()->fetchField());
  }

  /**
   * Unknown, malformed and authoritative domains never imply legacy authority.
   */
  public function testLegacyWriterRefusesUnknownAndPromotedAuthority(): void {
    $database = $this->container->get('database');
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $authority->transition('synthetic', 'agency', 'synthetic cutover');
    foreach (['agency', 'removed'] as $state) {
      if ($state === 'removed') {
        $authority->transition('synthetic', 'removed', 'synthetic removal');
      }
      $transaction = $database->startTransaction();
      try {
        $this->assertRefused(fn () => $authority->lockForLegacyWrite($database, 'synthetic'), 'legacy_writer_refused');
        $this->assertRefused(fn () => $authority->lockForLegacyWrite($database, 'missing'), 'legacy_writer_refused');
        $this->assertRefused(fn () => $authority->lockForLegacyWrite($database, '../invalid'), 'invalid_transition');
        $this->assertNull($authority->get('missing'));
      }
      finally {
        $transaction->rollBack();
      }
    }
  }

  /**
   * Missing operational schema does not enable old writers.
   */
  public function testLegacyWriterRefusesMissingStorage(): void {
    $database = $this->container->get('database');
    $database->schema()->dropTable('agency_migrate_authority_state');
    $transaction = $database->startTransaction();
    try {
      $this->assertRefused(fn () => $this->container->get('agency_migrate.authority')->lockForLegacyWrite($database, 'synthetic'), 'authority_storage_unavailable');
    }
    finally {
      $transaction->rollBack();
    }
  }

  /**
   * Savepoint release must not let a transition overtake the business write.
   */
  public function testLegacyWriterExcludesTransitionUntilRootCompletion(): void {
    $database = $this->container->get('database');
    $authority = $this->container->get('agency_migrate.authority');
    foreach ([FALSE, TRUE] as $commit) {
      $domain = $commit ? 'commit_fixture' : 'rollback_fixture';
      $authority->register($domain, 'initialize fixture');
      $paths = sys_get_temp_dir() . '/agency-legacy-writer-' . bin2hex(random_bytes(8));
      $vendor = dirname($this->root) . '/vendor';
      if (!is_file($vendor . '/autoload.php')) {
        $vendor = $this->root . '/vendor';
      }
      $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 3) . '/fixtures/migrate/authority-worker.php',
        $this->root,
        $vendor,
      ]);
      $process->setTimeout(20);
      $process->setInput(json_encode([
        'mode' => 'transition',
        'domain' => $domain,
        'database' => $database->getConnectionOptions(),
        'ready' => $paths . '-ready',
        'release' => $paths . '-release',
        'namespace' => dirname((new \ReflectionClass(AuthorityRegister::class))->getFileName()),
        'audit_namespace' => dirname((new \ReflectionClass(AuditChainLoggerInterface::class))->getFileName()),
      ], JSON_THROW_ON_ERROR));
      $transaction = NULL;
      $nested = NULL;
      try {
        $process->start();
        $deadline = microtime(TRUE) + 10;
        while (!is_file($paths . '-ready')) {
          if (!$process->isRunning() || microtime(TRUE) >= $deadline) {
            $this->fail('Independent writer did not reach its checkpoint: ' . $process->getErrorOutput());
          }
          usleep(20000);
        }
        $transaction = $database->startTransaction();
        $nested = $database->startTransaction();
        $this->assertSame(1, $authority->lockForLegacyWrite($database, $domain));
        TransactionBoundary::commit($nested);
        $nested = NULL;
        file_put_contents($paths . '-release', 'release');
        usleep(300000);
        $this->assertTrue($process->isRunning(), 'Transition must wait after savepoint release until the writer root ends.');
        if ($commit) {
          TransactionBoundary::commit($transaction);
        }
        else {
          $transaction->rollBack();
        }
        $transaction = NULL;
      }
      finally {
        $nested?->rollBack();
        $transaction?->rollBack();
        file_put_contents($paths . '-release', 'release');
        $process->wait();
        @unlink($paths . '-ready');
        @unlink($paths . '-release');
      }
      $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
      $result = json_decode($process->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR);
      $this->assertSame('committed', $result['outcome']);
      $this->assertSame('shadow', $authority->get($domain));
    }
  }

  /**
   * A waiting old writer must reject the newly committed authoritative state.
   */
  public function testLegacyWriterRechecksStateAfterConcurrentPromotion(): void {
    $database = $this->container->get('database');
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $paths = sys_get_temp_dir() . '/agency-legacy-promotion-' . bin2hex(random_bytes(8));
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $process = new Process([
      PHP_BINARY,
      dirname(__DIR__, 3) . '/fixtures/migrate/authority-worker.php',
      $this->root,
      $vendor,
    ]);
    $process->setTimeout(20);
    $process->setInput(json_encode([
      'mode' => 'legacy_write',
      'domain' => 'synthetic',
      'database' => $database->getConnectionOptions(),
      'ready' => $paths . '-ready',
      'release' => $paths . '-release',
      'namespace' => dirname((new \ReflectionClass(AuthorityRegister::class))->getFileName()),
      'audit_namespace' => dirname((new \ReflectionClass(AuditChainLoggerInterface::class))->getFileName()),
    ], JSON_THROW_ON_ERROR));
    $transaction = NULL;
    try {
      $process->start();
      $deadline = microtime(TRUE) + 10;
      while (!is_file($paths . '-ready')) {
        if (!$process->isRunning() || microtime(TRUE) >= $deadline) {
          $this->fail('Independent writer did not reach its checkpoint: ' . $process->getErrorOutput());
        }
        usleep(20000);
      }
      $transaction = $database->startTransaction();
      $authority->transition('synthetic', 'agency', 'synthetic cutover');
      file_put_contents($paths . '-release', 'release');
      usleep(300000);
      $this->assertTrue($process->isRunning(), 'Old writer waits for the authority transaction.');
      TransactionBoundary::commit($transaction);
      $transaction = NULL;
    }
    finally {
      $transaction?->rollBack();
      file_put_contents($paths . '-release', 'release');
      $process->wait();
      @unlink($paths . '-ready');
      @unlink($paths . '-release');
    }
    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    $result = json_decode($process->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame('refused', $result['outcome']);
    $this->assertSame('legacy_writer_refused', $result['reason']);
    $this->assertSame('agency', $authority->get('synthetic'));
  }

}
