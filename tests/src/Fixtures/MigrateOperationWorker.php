<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Fixtures;

use Composer\Autoload\ClassLoader;
use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;

/**
 * Independent operation worker using the coordinator's isolated SQL tables.
 *
 * @phpstan-ignore nonAbstractTestClass.NameNoSuffixTest (CLI fixture.)
 */
final class MigrateOperationWorker extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'key', 'encrypt', 'audit_chain',
    'agency', 'agency_migrate', 'agency_migrate_test', 'agency_migrate_operation_test',
  ];

  /**
   * Copies configuration for isolated boot; never mutates coordinator config.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $memory = new MemoryStorage();
    $database = new DatabaseStorage(Database::getConnection(), 'config');
    foreach ($database->listAll() as $name) {
      $memory->write($name, $database->read($name));
    }
    $container->set('config.storage.active', $memory);
    $container->getDefinition('config.storage.active')->setSynthetic(TRUE)->setPublic(TRUE);
  }

  /**
   * Shares only SQL records; services, config, caches and files are isolated.
   */
  public function runWorker(array $input, ClassLoader $loader, string $root): array {
    $this->root = $root;
    $this->classLoader = $loader;
    $this->databasePrefix = $input['test_prefix'];
    $this->setUpFilesystem();
    $this->initFileCache();
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
        throw new \RuntimeException('Coordinator did not release operation worker.');
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

}
