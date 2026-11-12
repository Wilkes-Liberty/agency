<?php

/**
 * @file
 * Independent authority writer restricted to an isolated kernel-test prefix.
 */

declare(strict_types=1);

use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\MigrateOutbox;
use Drupal\agency_migrate\UnavailableHostWriterFence;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

if (PHP_SAPI !== 'cli') {
  exit(1);
}
$loader = require $argv[2] . '/autoload.php';
require_once $argv[1] . '/core/includes/bootstrap.inc';
$input = json_decode(stream_get_contents(STDIN), TRUE, 32, JSON_THROW_ON_ERROR);
$loader->addPsr4('Drupal\\agency_migrate\\', $input['namespace']);
$loader->addPsr4('Drupal\\audit_chain\\', $input['audit_namespace']);
foreach (['mysql', 'pgsql'] as $driver) {
  $loader->addPsr4('Drupal\\' . $driver . '\\', $argv[1] . '/core/modules/' . $driver . '/src');
}
$prefix = $input['database']['prefix'] ?? '';
$prefix = is_array($prefix) ? ($prefix['default'] ?? '') : $prefix;
if (!preg_match('/^test[0-9]+/', $prefix)) {
  throw new RuntimeException('An isolated kernel-test prefix is required.');
}
Database::addConnectionInfo('worker', 'default', $input['database']);
$database = Database::getConnection('default', 'worker');
$storage = new DatabaseStorage($database, 'config');
$initial = $storage->read('agency_migrate.authority');
$builder = new class('authorityWorker') extends TestCase {

  /**
   * Uses real authority/outbox storage with only non-database test doubles.
   */
  public function runWorker(array $input, DatabaseStorage $storage, array $initial): void {
    $database = Database::getConnection('default', 'worker');
    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    \Drupal::setContainer($container);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $account->method('id')->willReturn(2);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(time());
    $logger = $this->createMock(AuditChainLoggerInterface::class);
    $outbox = new MigrateOutbox($database, $logger, new Php(), $time);
    $authority = new AuthorityRegister($account, $outbox, $database, $time, new UnavailableHostWriterFence());
    file_put_contents($input['ready'], 'ready');
    $deadline = microtime(TRUE) + 15;
    while (!is_file($input['release'])) {
      if (microtime(TRUE) >= $deadline) {
        throw new RuntimeException('Coordinator did not release worker.');
      }
      usleep(20000);
    }
    try {
      if ($input['mode'] === 'register') {
        $authority->register($input['domain'], 'second register');
      }
      else {
        $authority->transition($input['domain'], 'shadow', 'second transition');
      }
      print json_encode(['outcome' => 'committed', 'config_snapshot_domains' => count($initial['domains'] ?? [])], JSON_THROW_ON_ERROR);
    }
    catch (MigrateRefusal $e) {
      print json_encode(['outcome' => 'refused', 'reason' => $e->reason], JSON_THROW_ON_ERROR);
    }
  }

};
$builder->runWorker($input, $storage, $initial);
