<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers migrate copier plugins.
 *
 * @method \Drupal\agency_migrate\Plugin\CopierInterface createInstance($plugin_id, array $configuration = [])
 */
final class CopierManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/AgencyMigrate/Copier',
      $namespaces,
      $module_handler,
      CopierInterface::class,
      'Drupal\agency_migrate\Attribute\MigrateCopier',
      'Drupal\agency_migrate\Annotation\MigrateCopier',
    );
    $this->alterInfo('agency_migrate_copier_info');
    $this->setCacheBackend($cache_backend, 'agency_migrate_copier');
  }

  /**
   * Returns the copier whose target entity type matches.
   */
  public function copierForTarget(string $target_type): CopierInterface {
    foreach ($this->getDefinitions() as $plugin_id => $definition) {
      if (($definition['target_type'] ?? '') === $target_type) {
        return $this->createInstance($plugin_id);
      }
    }
    throw new \InvalidArgumentException('No copier is registered for that target type.');
  }

}
