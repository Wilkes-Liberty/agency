<?php

/**
 * @file
 * Boots a second real kernel against only the coordinator's isolated test DB.
 */

declare(strict_types=1);

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;

if (PHP_SAPI !== 'cli') {
  exit(1);
}
$loader = require $argv[2] . '/autoload.php';
$root = $argv[1];
chdir($root);
require_once $root . '/core/includes/bootstrap.inc';
$loader->addPsr4('Drupal\\KernelTests\\', $root . '/core/tests/Drupal/KernelTests');
$loader->addPsr4('Drupal\\Tests\\', $root . '/core/tests/Drupal/Tests');
$input = json_decode(stream_get_contents(STDIN), TRUE, 64, JSON_THROW_ON_ERROR);
$prefix = $input['database']['prefix'] ?? '';
$prefix = is_array($prefix) ? ($prefix['default'] ?? '') : $prefix;
if (!preg_match('/^test[0-9]+/D', $prefix)
  || !str_starts_with($input['site'], 'sites/simpletest/')) {
  throw new RuntimeException('An isolated kernel-test site is required.');
}
foreach (['mysql', 'pgsql'] as $driver) {
  $loader->addPsr4('Drupal\\' . $driver . '\\', $root . '/core/modules/' . $driver . '/src');
}
Database::addConnectionInfo('default', 'default', $input['database']);
new Settings(['hash_salt' => 'synthetic-operation-worker']);

$worker = new class('operationWorker') extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'key', 'encrypt', 'audit_chain',
    'agency', 'agency_migrate', 'agency_migrate_test', 'agency_migrate_operation_test',
  ];

  /**
   * Reuses installed tables with independent services, caches and connection.
   */
  public function runWorker(array $input, $loader, string $root): array {
    $this->root = $root;
    $this->classLoader = $loader;
    $this->siteDirectory = $input['site'];
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item']]);
    $this->bootKernel();
    $repository = $this->container->get('entity.last_installed_schema.repository');
    foreach (['user', 'agency_migrate_legacy_item', 'agency_migrate_agency_item'] as $type) {
      $repository->setLastInstalledDefinition($this->container->get('entity_type.manager')->getDefinition($type));
      $repository->setLastInstalledFieldStorageDefinitions($type, $this->container->get('entity_field.manager')->getFieldStorageDefinitions($type));
    }
    $database = $this->container->get('database');
    $pid = $database->query($database->driver() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchField();
    file_put_contents($input['ready'], (string) $pid);
    $deadline = microtime(TRUE) + 20;
    while (!is_file($input['release'])) {
      if (microtime(TRUE) > $deadline) {
        throw new RuntimeException('Coordinator did not release operation worker.');
      }
      usleep(20000);
    }
    try {
      if ($input['mode'] === 'transition') {
        $this->container->get('current_user')->setAccount($this->container->get('entity_type.manager')->getStorage('user')->load(2));
        $this->container->get('agency_migrate.authority')->transition('synthetic', 'removed', 'Synthetic concurrent transition');
        return ['status' => 'transitioned'];
      }
      return $this->container->get('agency_migrate.post_cutover_writer')->create('synthetic', 3, $input['operation_id'], 'agency_migrate_agency_item', ['title' => 'Concurrent once']);
    }
    catch (MigrateRefusal $exception) {
      return ['status' => 'refused', 'reason' => $exception->reason];
    }
  }

};
print json_encode($worker->runWorker($input, $loader, $root), JSON_THROW_ON_ERROR);
