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
    $announce = static function () use ($input, $connection_id): void {
      file_put_contents($input['started'] . '.new', (string) $connection_id);
      rename($input['started'] . '.new', $input['started']);
    };
    if ($input['mode'] === 'policy') {
      $storage = new DatabaseStorage($database, 'config');
      $policy = $storage->read('agency.settings');
      $policy['policy']['retention_after_close'] = 'P3D';
      $announce();
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
    if (in_array($input['mode'], ['domain', 'domain_policy', 'retention_reference', 'retention_policy'], TRUE)) {
      $store = new DomainStore($database, $entities);
      $announce();
      $result = $store->transaction(static function () use ($checkpoint, $input, $database): array {
        if (in_array($input['mode'], ['retention_reference', 'retention_policy'], TRUE)) {
          if ($input['mode'] === 'retention_reference') {
            foreach (['agency_discovery', 'agency_discovery_revision'] as $table) {
              $database->update($table)->fields([
                'person_uuid' => $input['person'],
                'principal_uuid' => $input['principal'],
              ])->condition('id', $input['other_id'])->execute();
            }
          }
          else {
            $storage = new DatabaseStorage($database, 'config');
            $policy = $storage->read('agency.settings');
            $policy['policy']['retention_after_close'] = 'P2D';
            $storage->write('agency.settings', $policy);
          }
          file_put_contents($input['ready'], 'ready');
          $deadline = microtime(TRUE) + 15;
          do {
            if ($database->driver() === 'pgsql') {
              $database->query('SELECT pg_stat_clear_snapshot()');
              $waiting = $database->query("SELECT query FROM pg_stat_activity WHERE pid = :id AND wait_event_type = 'Lock'", [':id' => $input['parent_id']])->fetchField();
            }
            else {
              $waiting = $database->query("SELECT INFO FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = :id AND COMMAND = 'Query'", [':id' => $input['parent_id']])->fetchField();
            }
            if (is_string($waiting) && str_contains($waiting, $database->getPrefix() . 'agency_staff_mutex')
              && preg_match('/\bFOR\s+UPDATE\b/i', $waiting) === 1) {
              break;
            }
            if (microtime(TRUE) >= $deadline) {
              throw new RuntimeException('Removal did not reach the held domain mutex.');
            }
            usleep(20000);
          } while (TRUE);
          file_put_contents($input['observed'], 'blocked');
          if ($input['rollback']) {
            throw new RuntimeException('Synthetic reference rollback.');
          }
          return ['outcome' => 'committed'];
        }
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
    $announce();
    print json_encode(['delivered' => $outbox->drain()], JSON_THROW_ON_ERROR);
  }

};
$builder->runWorker($input);
