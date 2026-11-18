<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\HostWriterFenceInterface;
use Drupal\agency_migrate\RollbackService;
use Drupal\agency_migrate\TransactionBoundary;
use Drupal\agency_migrate\UnavailableHostWriterFence;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;

/**
 * Operational authority cannot be replaced by imported or stale configuration.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateAuthorityStorageTest extends MigrateKernelTestBase {

  /**
   * A configuration import cannot promote or overwrite runtime authority.
   */
  public function testConfigImportCannotChangeOperationalAuthority(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initial register');
    $this->config('agency_migrate.authority')->set('domains.synthetic.state', 'agency')->save();
    $this->assertSame('legacy', $authority->get('synthetic'));
    $this->assertSame('legacy', $authority->status()['synthetic']['state']);
  }

  /**
   * A worker's stale configuration cannot replace another domain's commit.
   */
  public function testIndependentRegistrationPreservesBothDomains(): void {
    [$process, $ready, $release] = $this->worker('register', 'second');
    try {
      $process->start();
      $this->waitFor($process, $ready);
      $this->container->get('agency_migrate.authority')->register('first', 'first register');
    }
    finally {
      file_put_contents($release, 'release');
      $process->wait();
      @unlink($ready);
      @unlink($release);
    }
    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    $this->container->get('config.factory')->reset('agency_migrate.authority');
    $status = $this->container->get('agency_migrate.authority')->status();
    $this->assertSame('legacy', $status['first']['state'] ?? NULL);
    $this->assertSame('legacy', $status['second']['state'] ?? NULL);
  }

  /**
   * A blocked writer must reread the committed state before transitioning.
   */
  public function testCompetingTransitionRejectsStaleState(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initial register');
    [$process, $ready, $release] = $this->worker('transition', 'synthetic');
    $transaction = NULL;
    try {
      $process->start();
      $this->waitFor($process, $ready);
      $transaction = $this->container->get('database')->startTransaction();
      $authority->transition('synthetic', 'shadow', 'first transition');
      file_put_contents($release, 'release');
      usleep(300000);
      $this->assertTrue($process->isRunning(), 'Independent connection must wait on the uncommitted authority row.');
      TransactionBoundary::commit($transaction);
      $transaction = NULL;
    }
    finally {
      $transaction?->rollBack();
      file_put_contents($release, 'release');
      $process->wait();
      @unlink($ready);
      @unlink($release);
    }
    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    $this->assertSame('invalid_transition', json_decode($process->getOutput(), TRUE)['reason'] ?? NULL);
    $events = (int) $this->container->get('database')->select('agency_migrate_authority_event', 'e')->countQuery()->execute()->fetchField();
    $this->assertSame(2, $events, 'Exactly one transition and the initial registration commit.');
  }

  /**
   * The unique domain key arbitrates two independent registrations.
   */
  public function testCompetingRegistrationRejectsDuplicate(): void {
    [$process, $ready, $release] = $this->worker('register', 'synthetic');
    $transaction = NULL;
    try {
      $process->start();
      $this->waitFor($process, $ready);
      $transaction = $this->container->get('database')->startTransaction();
      $this->container->get('agency_migrate.authority')->register('synthetic', 'first register');
      file_put_contents($release, 'release');
      usleep(300000);
      $this->assertTrue($process->isRunning(), 'Independent registration must wait for the conflicting uncommitted key.');
      TransactionBoundary::commit($transaction);
      $transaction = NULL;
    }
    finally {
      $transaction?->rollBack();
      file_put_contents($release, 'release');
      $process->wait();
      @unlink($ready);
      @unlink($release);
    }
    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    $this->assertSame('invalid_transition', json_decode($process->getOutput(), TRUE)['reason'] ?? NULL);
    $events = (int) $this->container->get('database')->select('agency_migrate_authority_event', 'e')->countQuery()->execute()->fetchField();
    $this->assertSame(1, $events);
  }

  /**
   * Schema absence cannot silently return an unregistered/legacy state.
   */
  public function testMissingAuthorityTableFailsClosed(): void {
    $this->container->get('database')->schema()->dropTable('agency_migrate_authority_state');
    $this->assertRefused(fn () => $this->container->get('agency_migrate.authority')->get('synthetic'), 'authority_storage_unavailable');
  }

  /**
   * Upgrade preserves recorded states once, without later config re-import.
   */
  public function testUpgradeImportsExistingAuthorityOnce(): void {
    $database = $this->container->get('database');
    $database->schema()->dropTable('agency_migrate_authority_state');
    $legacy = [
      'synthetic' => [
        'state' => 'agency',
        'changed' => 123,
        'actor_hash' => str_repeat('a', 64),
        'reason' => 'historical cutover',
      ],
    ];
    $this->config('agency_migrate.authority')->set('domains', $legacy)->save();
    agency_migrate_update_10002();
    $authority = $this->container->get('agency_migrate.authority');
    $this->assertSame('agency', $authority->get('synthetic'));
    $this->assertSame(1, $authority->status()['synthetic']['version']);
    $this->assertSame($legacy, $this->config('agency_migrate.authority')->get('domains'));
    $this->config('agency_migrate.authority')->set('domains.injected', $legacy['synthetic'])->save();
    agency_migrate_update_10002();
    $this->assertNull($authority->get('injected'));
  }

  /**
   * The production default cannot promote authority without host coverage.
   */
  public function testDefaultFenceRefusesAuthoritativeTransition(): void {
    $database = $this->container->get('database');
    $authority = new AuthorityRegister(
      $this->container->get('current_user'),
      $this->container->get('agency_migrate.outbox'),
      $database,
      $this->container->get('datetime.time'),
      new UnavailableHostWriterFence(),
    );
    $authority->register('synthetic', 'initial register');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $before = $authority->status();
    $this->assertRefused(fn () => $authority->assertTransition('synthetic', 'agency', 'cutover'), 'writer_fence_unavailable');
    $this->assertRefused(fn () => $authority->transition('synthetic', 'agency', 'cutover'), 'writer_fence_unavailable');
    $this->assertSame($before, $authority->status());
    $this->assertSame(2, (int) $database->select('agency_migrate_authority_event', 'e')->countQuery()->execute()->fetchField());
    $this->assertSame(2, (int) $database->select('agency_migrate_outbox', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * Replay must not read its pending snapshot before exclusion is acquired.
   */
  public function testRollbackAcquiresFenceBeforePendingSnapshot(): void {
    $normal = $this->container->get('agency_migrate.authority');
    $normal->register('synthetic', 'initial register');
    $normal->transition('synthetic', 'shadow', 'begin shadow');
    $normal->transition('synthetic', 'agency', 'synthetic cutover');
    $fence = new class implements HostWriterFenceInterface {

      /**
       * {@inheritdoc}
       */
      public function assertAvailable(string $domain): void {}

      /**
       * {@inheritdoc}
       */
      public function acquire(string $domain): void {
        throw new MigrateRefusal('writer_fence_unavailable');
      }

    };
    $database = $this->container->get('database');
    $authority = new AuthorityRegister(
      $this->container->get('current_user'),
      $this->container->get('agency_migrate.outbox'),
      $database,
      $this->container->get('datetime.time'),
      $fence,
    );
    $rollback = new RollbackService(
      $this->container->get('plugin.manager.agency_migrate.copier'),
      $this->container->get('agency_migrate.rollback_replay_store'),
      $authority,
      $this->container->get('current_user'),
      $this->container->get('agency_migrate.reconciler'),
      $this->container->get('agency_migrate.rollback_planner'),
      $database,
      $this->container->get('agency_migrate.outbox'),
    );
    $before = $authority->status();
    $database->schema()->renameTable('agency_migrate_write_log', 'agency_migrate_write_log_unavailable');
    try {
      $this->assertRefused(fn () => $rollback->rollback('synthetic', 'rehearsal rollback', TRUE), 'writer_fence_unavailable');
    }
    finally {
      $this->assertFalse($database->inTransaction(), 'Fence acquisition failure must close its transaction before DDL cleanup.');
      $database->schema()->renameTable('agency_migrate_write_log_unavailable', 'agency_migrate_write_log');
    }
    $this->assertSame($before, $authority->status());
  }

  /**
   * Evidence storage failure must roll back the operational generation/event.
   */
  public function testEvidenceFailureRollsBackAuthorityStateAndEvent(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initial register');
    $database = $this->container->get('database');
    $before = $authority->status();
    $database->schema()->renameTable('agency_migrate_outbox', 'agency_migrate_outbox_unavailable');
    try {
      try {
        $authority->transition('synthetic', 'shadow', 'begin shadow');
        $this->fail('Unavailable evidence storage must refuse the transition.');
      }
      catch (DatabaseExceptionWrapper) {
        $this->assertSame($before, $authority->status());
        $this->assertSame(1, (int) $database->select('agency_migrate_authority_event', 'e')->countQuery()->execute()->fetchField());
      }
    }
    finally {
      $this->assertFalse($database->inTransaction(), 'Evidence failure must close its transaction before DDL cleanup.');
      $database->schema()->renameTable('agency_migrate_outbox_unavailable', 'agency_migrate_outbox');
    }
    $this->assertSame(1, (int) $database->select('agency_migrate_outbox', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * Starts an isolated-prefix worker with a deliberately stale config snapshot.
   */
  private function worker(string $mode, string $domain): array {
    $database = $this->container->get('database');
    $this->assertContains($database->driver(), ['mysql', 'pgsql']);
    $paths = sys_get_temp_dir() . '/agency-authority-' . bin2hex(random_bytes(8));
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
      'mode' => $mode,
      'domain' => $domain,
      'database' => $database->getConnectionOptions(),
      'ready' => $paths . '-ready',
      'release' => $paths . '-release',
      'namespace' => dirname((new \ReflectionClass(AuthorityRegister::class))->getFileName()),
      'audit_namespace' => dirname((new \ReflectionClass(AuditChainLoggerInterface::class))->getFileName()),
    ], JSON_THROW_ON_ERROR));
    return [$process, $paths . '-ready', $paths . '-release'];
  }

  /**
   * Waits for an independent process checkpoint without hiding a worker fault.
   */
  private function waitFor(Process $process, string $path): void {
    $deadline = microtime(TRUE) + 10;
    while (!is_file($path)) {
      if (!$process->isRunning() || microtime(TRUE) >= $deadline) {
        $this->fail('Worker did not reach checkpoint: ' . $process->getErrorOutput());
      }
      usleep(20000);
    }
  }

}
