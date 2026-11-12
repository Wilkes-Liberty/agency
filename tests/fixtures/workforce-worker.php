<?php

/**
 * @file
 * Independent synthetic workforce writer using the portable service contract.
 */

declare(strict_types=1);

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\agency_workforce\InvitationJournal;
use Drupal\agency_workforce\InvitationService;
use Drupal\agency_workforce\RootTransactionCommitter;
use Drupal\agency_workforce\TransactionalStaffProvisionerInterface;
use Drupal\agency_workforce_test\SqlInvitationStore;
use Drupal\agency_workforce_test\SqlWriterBoundary;
use Drupal\agency_workforce_test\SyntheticClock;
use Drupal\agency_workforce_test\SyntheticPolicy;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
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
Database::addConnectionInfo('workforce_worker', 'default', $input['database']);
$database = Database::getConnection('default', 'workforce_worker');
if ($database->driver() === 'pgsql') {
  $database->query("SET lock_timeout = '15s'");
}
else {
  $database->query('SET SESSION innodb_lock_wait_timeout = 15');
}
require_once $input['module_root'] . '/agency.module';

$runner = new class('workforceWorker') extends TestCase {

  /**
   * Executes real SQL writes and exclusion, with synthetic trusted context.
   */
  public function runWorker(array $input): array {
    $database = Database::getConnection('default', 'workforce_worker');
    $id = $database->query($database->driver() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchField();
    file_put_contents($input['started'], (string) $id);
    if (($input['mode'] ?? '') === 'authority') {
      $transaction = $database->startTransaction();
      $database->update('agency_workforce_test_authority')->fields(['state' => 'agency'])
        ->condition('scope', 'synthetic-workforce')->execute();
      (new RootTransactionCommitter())->commit($transaction);
      return ['status' => 'authority_changed'];
    }
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(function (string $name) use ($database): ImmutableConfig {
      $data = (new DatabaseStorage($database, 'config'))->read($name) ?: [];
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturnCallback(static function (string $key) use ($data): mixed {
        $value = $data;
        foreach (explode('.', $key) as $part) {
          if (!is_array($value) || !array_key_exists($part, $value)) {
            return NULL;
          }
          $value = $value[$part];
        }
        return $value;
      });
      return $config;
    });
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);
    $current = $this->createMock(AccountProxyInterface::class);
    $current->method('isAnonymous')->willReturn(TRUE);
    $current->method('hasPermission')->willReturn(FALSE);
    $requests = new RequestStack();
    $requests->push(Request::create('/onboard', 'POST'));
    $clock = new SyntheticClock($factory);
    $provisioner = new class($database, $input) implements TransactionalStaffProvisionerInterface {

      public function __construct(
        private readonly Connection $database,
        private readonly array $input,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function connection(): Connection {
        return $this->database;
      }

      /**
       * {@inheritdoc}
       */
      public function accountExists(string $normalizedContact): bool {
        return (bool) $this->database->select('agency_workforce_test_concurrent_account', 'a')
          ->condition('contact', $normalizedContact)->countQuery()->execute()->fetchField();
      }

      /**
       * {@inheritdoc}
       */
      public function provision(string $normalizedContact, string $classification, string $language, string $preferredName): string {
        $id = $this->database->insert('agency_workforce_test_concurrent_account')->fields([
          'contact' => $normalizedContact, 'classification' => $classification,
        ])->execute();
        if (isset($this->input['ready'])) {
          touch($this->input['ready']);
          $deadline = microtime(TRUE) + 20;
          while (!is_file($this->input['release'])) {
            if (microtime(TRUE) > $deadline) {
              throw new RuntimeException('Synthetic provisioner hold timed out.');
            }
            usleep(20000);
          }
        }
        $this->database->insert('agency_workforce_test_concurrent_profile')->fields([
          'account_id' => (int) $id, 'classification' => $classification, 'language' => $language,
        ])->execute();
        return 'synthetic:' . $id;
      }

      /**
       * {@inheritdoc}
       */
      public function verifyBinding(string $binding, string $normalizedContact, string $classification, string $language): bool {
        if (!preg_match('/^synthetic:([1-9][0-9]*)$/D', $binding, $match)) {
          return FALSE;
        }
        $query = $this->database->select('agency_workforce_test_concurrent_account', 'a');
        $query->innerJoin('agency_workforce_test_concurrent_profile', 'p', 'p.account_id = a.id');
        return (bool) $query->condition('a.id', (int) $match[1])
          ->condition('a.contact', $normalizedContact)->condition('a.classification', $classification)
          ->condition('p.classification', $classification)->condition('p.language', $language)
          ->countQuery()->execute()->fetchField();
      }

    };
    $journal = new InvitationJournal($database, $this->createMock(AuditChainLoggerInterface::class), $clock);
    $service = new InvitationService(
      $database, new SqlInvitationStore($database, $factory), $provisioner,
      new SyntheticPolicy($factory, $current), new SqlWriterBoundary(), $clock,
      $current, $requests, new Php(), new RootTransactionCommitter(), $journal,
    );
    return $service->accept($input['bearer']);
  }

};
try {
  print json_encode($runner->runWorker($input), JSON_THROW_ON_ERROR);
}
catch (InvitationRefusal $exception) {
  print json_encode(['status' => 'refused', 'reason' => $exception->reason], JSON_THROW_ON_ERROR);
}
catch (Throwable $exception) {
  print json_encode(['status' => 'error', 'class' => get_class($exception)], JSON_THROW_ON_ERROR);
  exit(1);
}
