<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel;

use Drupal\agency_staff\DependencyInjection\RetentionPolicyWiringPass;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\LockedRetentionPolicy;
use Drupal\agency_staff\Policy;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\agency_staff\Storage\TransactionBoundary;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Process\Process;

/**
 * Proves the predeclared policy-lock and alternate-backend refusal boundaries.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class RetentionPolicyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'key', 'encrypt',
    'audit_chain', 'field_guard', 'agency', 'agency_staff',
  ];

  /**
   * Actual compiled default wiring reads the authoritative configured policy.
   */
  public function testDefaultCompiledPolicyAndCacheDrift(): void {
    $this->config('agency.settings')->setData([
      'policy' => [
        'retention_after_close' => 'P2D',
        'retained_information_statement' => 'Synthetic retained information.',
      ],
    ])->save();
    $database = $this->container->get('database');
    $fence = $this->container->get('agency_staff.retention_policy');
    $transaction = $database->startTransaction();
    try {
      $first = $fence->capture(TRUE);
      $this->assertSame('P2D', $first['facts']['retention_period']);
      // Prime the cached active storage, then change only authoritative SQL.
      // ConfigFactory::reset() must not make stale cached policy trustworthy.
      $active = new DatabaseStorage($database, 'config');
      $stored = $active->read('agency.settings');
      $stored['policy']['retention_after_close'] = 'P1D';
      $active->write('agency.settings', $stored);
      try {
        $fence->capture(TRUE);
        $this->fail('Cached policy must not authorize retention removal.');
      }
      catch (Refusal $exception) {
        $this->assertSame('policy_unavailable', $exception->reason);
      }
      $this->container->get('cache.config')->invalidateAll();
      $fresh = $fence->capture(TRUE);
      $this->assertSame('P1D', $fresh['facts']['retention_period']);
      $this->assertNotSame($first['stored_sha256'], $fresh['stored_sha256']);
      $this->assertNotSame($first['effective_sha256'], $fresh['effective_sha256']);
    }
    finally {
      $transaction->rollBack();
    }
  }

  /**
   * Missing rows and caller-owned boundary mistakes never invent policy.
   */
  public function testMissingPolicyAndRootBoundary(): void {
    $fence = $this->container->get('agency_staff.retention_policy');
    try {
      $fence->capture(TRUE);
      $this->fail('The policy lock must belong to an existing root transaction.');
    }
    catch (\LogicException) {
      $this->assertFalse($this->container->get('database')->inTransaction());
    }
    $transaction = $this->container->get('database')->startTransaction();
    try {
      $fence->capture(TRUE);
      $this->fail('Missing policy is not a default retention period.');
    }
    catch (Refusal $exception) {
      $this->assertSame('policy_unavailable', $exception->reason);
    }
    finally {
      $transaction->rollBack();
    }
  }

  /**
   * A real configuration writer waits for the policy owner's commit.
   */
  public function testPolicyWriterWaitsForCommit(): void {
    $this->concurrentPolicyWriter(FALSE);
  }

  /**
   * The same row fence releases after rollback without losing the next write.
   */
  public function testPolicyWriterWaitsForRollback(): void {
    $this->concurrentPolicyWriter(TRUE);
  }

  /**
   * A waiting domain writer must observe what the previous writer committed.
   */
  public function testDomainInventoryAfterWaitingWriter(): void {
    $this->concurrentPolicyWriter(FALSE, TRUE);
  }

  /**
   * A previous writer's rolled-back changes cannot become inventory authority.
   */
  public function testDomainInventoryAfterRolledBackWriter(): void {
    $this->concurrentPolicyWriter(TRUE, TRUE);
  }

  /**
   * Uses an independent process and connection against the real config table.
   */
  private function concurrentPolicyWriter(bool $rollback, bool $domain = FALSE): void {
    $this->config('agency.settings')->setData([
      'policy' => [
        'retention_after_close' => 'P2D',
        'retained_information_statement' => 'Synthetic retained information.',
      ],
    ])->save();
    $database = $this->container->get('database');
    $this->assertContains($database->driver(), ['pgsql', 'mysql']);
    $fence = $this->container->get('agency_staff.retention_policy');
    if ($domain) {
      $this->installSchema('agency_staff', ['agency_staff_mutex']);
      $database->insert('agency_staff_mutex')->fields(['id' => 1, 'value' => 0])->execute();
    }
    $transaction = $database->startTransaction();
    if ($domain) {
      $database->update('agency_staff_mutex')->fields(['value' => 0])->condition('id', 1)->execute();
      $active = new DatabaseStorage($database, 'config');
      $stored = $active->read('agency.settings');
      $stored['policy']['retention_after_close'] = 'P3D';
      $active->write('agency.settings', $stored);
    }
    else {
      $this->assertSame('P2D', $fence->capture(TRUE)['facts']['retention_period']);
    }
    $marker = sys_get_temp_dir() . '/agency-policy-' . bin2hex(random_bytes(8));
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $worker = new Process([
      PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/discovery-worker.php', $this->root, $vendor,
    ]);
    $worker->setTimeout(25);
    $worker->setInput(json_encode([
      'mode' => $domain ? 'domain_policy' : 'policy',
      'database' => $database->getConnectionOptions(),
      'namespaces' => [
        'Drupal\\agency_staff\\' => dirname((new \ReflectionClass(DomainStore::class))->getFileName(), 2),
      ],
      'started' => $marker,
    ], JSON_THROW_ON_ERROR));
    try {
      $worker->start();
      $deadline = microtime(TRUE) + 10;
      while (!is_file($marker)) {
        if (!$worker->isRunning() || microtime(TRUE) >= $deadline) {
          $this->fail('Configuration writer did not start: ' . $worker->getErrorOutput());
        }
        usleep(20000);
      }
      usleep(300000);
      $this->assertTrue($worker->isRunning(), 'The next writer must wait for the current lock owner.');
      if (!$domain) {
        $this->assertSame('P2D', $fence->capture(TRUE)['facts']['retention_period']);
      }
      if ($rollback) {
        $transaction->rollBack();
        $transaction = NULL;
      }
      else {
        TransactionBoundary::commit($transaction);
      }
      $worker->wait();
      $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
      $output = json_decode($worker->getOutput(), TRUE, 16, JSON_THROW_ON_ERROR);
      if ($domain) {
        $this->assertSame(['outcome' => 'observed', 'period' => $rollback ? 'P2D' : 'P3D'], $output);
      }
      else {
        $this->assertTrue($output['written']);
      }
      $this->container->get('cache.config')->invalidateAll();
      $transaction = $database->startTransaction();
      $this->assertSame($domain && $rollback ? 'P2D' : 'P3D', $fence->capture(TRUE)['facts']['retention_period']);
    }
    finally {
      $transaction?->rollBack();
      $worker->stop();
      if (is_file($marker)) {
        unlink($marker);
      }
    }
  }

  /**
   * Alternate service graphs cannot silently inherit a default-SQL guarantee.
   */
  public function testAlternateWiringDisablesOnlyRetention(): void {
    $changes = [
      'table' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setArgument(1, 'other_config'),
      'connection' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setArgument(0, new Reference('other_database')),
      'options' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setArgument(2, ['target' => 'replica']),
      'collection' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setArgument(3, 'other'),
      'active factory' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setFactory([
        'SyntheticFactory', 'create',
      ]),
      'active configurator' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setConfigurator([
        'SyntheticFactory', 'configure',
      ]),
      'active method' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->addMethodCall('changeBackend'),
      'active property' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setProperty('table', 'other'),
      'active decorator' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setDecoratedService('other_storage'),
      'active replacement' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage.active')->setClass('SyntheticStorage'),
      'cache replacement' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage')->setClass('SyntheticCachedStorage'),
      'cache backend' => static fn(ContainerBuilder $c) => $c->getDefinition('config.storage')->setArgument(0, new Reference('other_storage')),
      'factory replacement' => static fn(ContainerBuilder $c) => $c->getDefinition('config.factory')->setClass('SyntheticConfigFactory'),
      'factory storage' => static fn(ContainerBuilder $c) => $c->getDefinition('config.factory')->setArgument(0, new Reference('other_storage')),
      'factory mutation' => static fn(ContainerBuilder $c) => $c->getDefinition('config.factory')->addMethodCall('setOverrideState', [TRUE]),
      'nonshared database' => static fn(ContainerBuilder $c) => $c->getDefinition('database')->setShared(FALSE),
      'different domain connection' => static fn(ContainerBuilder $c) => $c->getDefinition('agency_staff.store')->setArgument(0, new Reference('other_database')),
      'different policy factory' => static fn(ContainerBuilder $c) => $c->getDefinition('agency_staff.policy')->setArgument(0, new Reference('other_factory')),
      'policy replacement' => static fn(ContainerBuilder $c) => $c->getDefinition('agency_staff.policy')->setClass('SyntheticPolicy'),
    ];
    $pass = new RetentionPolicyWiringPass();
    $ordinary = $this->wiring();
    $pass->process($ordinary);
    $this->assertTrue($ordinary->getDefinition('agency_staff.retention_policy')->getArgument(4));
    foreach ($changes as $name => $change) {
      $container = $this->wiring();
      $change($container);
      $pass->process($container);
      $this->assertFalse($container->getDefinition('agency_staff.retention_policy')->getArgument(4), $name);
      $this->assertTrue($container->hasDefinition('config.factory'), 'Unsupported retention must not disable the site.');
    }
  }

  /**
   * Supplies the real core service graph shapes without constructing a backend.
   */
  private function wiring(): ContainerBuilder {
    $container = new ContainerBuilder();
    $container->setDefinition('database', (new Definition(Connection::class))->setSynthetic(TRUE));
    $container->setDefinition('agency_staff.store', new Definition(DomainStore::class, [new Reference('database')]));
    $container->setDefinition('config.storage.active', new Definition(DatabaseStorage::class, [
      new Reference('database'), 'config',
    ]));
    $container->setDefinition('config.storage', new Definition(CachedStorage::class, [
      new Reference('config.storage.active'), new Reference('cache.config'),
    ]));
    $container->setDefinition('config.factory', new Definition(ConfigFactory::class, [
      new Reference('config.storage'), new Reference('event_dispatcher'), new Reference('config.typed'),
    ]));
    $container->setDefinition('agency_staff.policy', new Definition(Policy::class, [
      new Reference('config.factory'), new Reference('datetime.time'),
    ]));
    $container->setDefinition('agency_staff.retention_policy', new Definition(LockedRetentionPolicy::class, [
      new Reference('database'), new Reference('config.factory'),
      new Reference('config.storage.active'), new Reference('agency_staff.policy'), FALSE,
    ]));
    return $container;
  }

}
