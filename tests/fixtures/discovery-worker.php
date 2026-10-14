<?php

/**
 * @file
 * Independent database writer for the predeclared Discovery failure matrix.
 */

declare(strict_types=1);

use Drupal\agency_staff\EvidenceOutbox;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\audit_chain\AuditChainLogger;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\key\Entity\Key;
use Drupal\key\KeyRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

if (PHP_SAPI !== 'cli') {
  exit(1);
}
$root = $argv[1];
$loader = require $argv[2] . '/autoload.php';
require_once $root . '/core/includes/bootstrap.inc';
$input = json_decode(stream_get_contents(STDIN), TRUE, 32, JSON_THROW_ON_ERROR);
foreach ($input['namespaces'] as $namespace => $path) {
  $loader->addPsr4($namespace, $path);
}
foreach (['mysql', 'pgsql'] as $driver) {
  $loader->addPsr4('Drupal\\' . $driver . '\\', $root . '/core/modules/' . $driver . '/src');
}
$prefix = $input['database']['prefix'] ?? '';
$prefix = is_array($prefix) ? ($prefix['default'] ?? '') : $prefix;
if (!preg_match('/^test[0-9]+/', $prefix)) {
  throw new RuntimeException('An isolated kernel-test prefix is required.');
}
Database::addConnectionInfo('worker', 'default', $input['database']);
$database = Database::getConnection('default', 'worker');
if ($database->driver() === 'pgsql') {
  $database->query("SET lock_timeout = '15s'");
}
else {
  $database->query('SET SESSION innodb_lock_wait_timeout = 15');
}

$builder = new class('discoveryWorker') extends TestCase {

  /**
   * Constructs the real services with synthetic non-database dependencies.
   */
  public function runWorker(array $input): void {
    $database = Database::getConnection('default', 'worker');
    $connection_id = $database->query($database->driver() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchField();
    if ($input['mode'] === 'policy') {
      $storage = new DatabaseStorage($database, 'config');
      $policy = $storage->read('agency.settings');
      $policy['policy']['retention_after_close'] = 'P3D';
      file_put_contents($input['started'], (string) $connection_id);
      print json_encode(['written' => $storage->write('agency.settings', $policy)], JSON_THROW_ON_ERROR);
      return;
    }
    if ($input['mode'] === 'domain_policy' && $database->driver() === 'mysql') {
      $database->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $checkpoint = static function () use ($input): void {
      if (!isset($input['ready'])) {
        return;
      }
      file_put_contents($input['ready'], 'ready');
      $deadline = microtime(TRUE) + 20;
      while (!is_file($input['release'])) {
        if (microtime(TRUE) >= $deadline) {
          throw new RuntimeException('Test coordinator did not release writer.');
        }
        usleep(20000);
      }
    };
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturn($this->createMock(EntityStorageInterface::class));
    if (in_array($input['mode'], ['domain', 'domain_policy'], TRUE)) {
      $store = new DomainStore($database, $entities);
      file_put_contents($input['started'], (string) $connection_id);
      $result = $store->transaction(static function () use ($checkpoint, $input, $database): array {
        $checkpoint();
        if ($input['mode'] === 'domain_policy') {
          $stored = (new DatabaseStorage($database, 'config'))->read('agency.settings');
          return ['outcome' => 'observed', 'period' => $stored['policy']['retention_after_close']];
        }
        if ($input['rollback'] ?? FALSE) {
          throw new RuntimeException('Synthetic domain failure.');
        }
        return ['outcome' => 'committed'];
      });
      print json_encode($result, JSON_THROW_ON_ERROR);
      return;
    }
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['hash_key', 'discovery_test'],
      ['encryption_profile', NULL],
      ['stream_enabled', FALSE],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $key = $this->createMock(Key::class);
    $key->method('getKeyValue')->willReturn('synthetic-discovery-test-key');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturn($key);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(0);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1);
    $time->method('getCurrentTime')->willReturn(1);
    $chain = new AuditChainLogger(
      $database, $account, new RequestStack(), $factory, $time, $keys,
      new NullLogger(), $this->createMock(EncryptServiceInterface::class),
      $entities, $this->createMock(StateInterface::class),
    );
    $logger = $this->createMock(AuditChainLoggerInterface::class);
    $logger->method('logKeyed')->willReturnCallback(static function (string $channel, string $operation, array $metadata) use ($chain, $checkpoint, $input): void {
      $chain->logKeyed($channel, $operation, $metadata);
      $checkpoint();
      if ($input['rollback'] ?? FALSE) {
        throw new RuntimeException('Synthetic interruption after append.');
      }
    });
    $outbox = new EvidenceOutbox($database, $logger, new Php(), $time);
    file_put_contents($input['started'], 'started');
    print json_encode(['delivered' => $outbox->drain()], JSON_THROW_ON_ERROR);
  }

};
$builder->runWorker($input);
