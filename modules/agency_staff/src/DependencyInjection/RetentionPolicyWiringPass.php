<?php

declare(strict_types=1);

namespace Drupal\agency_staff\DependencyInjection;

use Drupal\agency_staff\LockedRetentionPolicy;
use Drupal\agency_staff\Policy;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\DatabaseStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Enables the default SQL policy fence only for the inspected service graph.
 */
final class RetentionPolicyWiringPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    if (!$container->hasDefinition('agency_staff.retention_policy')) {
      return;
    }
    $fence = $container->getDefinition('agency_staff.retention_policy');
    $fence->setArgument(4, FALSE);
    try {
      $active = $container->findDefinition('config.storage.active');
      $cached = $container->findDefinition('config.storage');
      $factory = $container->findDefinition('config.factory');
      $store = $container->findDefinition('agency_staff.store');
      $policy = $container->findDefinition('agency_staff.policy');
      $database = $container->findDefinition('database');
      if (!$this->ordinary($fence, LockedRetentionPolicy::class)
        || !$this->ordinary($active, DatabaseStorage::class)
        || !$this->ordinary($cached, CachedStorage::class)
        || !$this->ordinary($factory, ConfigFactory::class, TRUE)
        || !$this->ordinary($store, DomainStore::class)
        || !$this->ordinary($policy, Policy::class)
        || !$database->isShared()) {
        return;
      }
      foreach ([
        [$fence, 0, 'database'], [$fence, 1, 'config.factory'],
        [$fence, 2, 'config.storage.active'], [$store, 0, 'database'],
        [$fence, 3, 'agency_staff.policy'], [$policy, 0, 'config.factory'],
        [$active, 0, 'database'], [$cached, 0, 'config.storage.active'],
        [$factory, 0, 'config.storage'],
      ] as [$definition, $position, $target]) {
        $argument = $definition->getArgument($position);
        if (!$argument instanceof Reference
          || $container->findDefinition((string) $argument) !== $container->findDefinition($target)) {
          return;
        }
      }
      $arguments = $active->getArguments();
      if (($arguments[1] ?? NULL) !== 'config'
        || ($arguments[2] ?? []) !== [] || ($arguments[3] ?? '') !== ''
        || count($arguments) > 4) {
        return;
      }
      $fence->setArgument(4, TRUE);
    }
    catch (\Throwable) {
      // An unknown backend makes only this maintenance capability unavailable.
    }
  }

  /**
   * Rejects alternate construction or mutation of a trusted implementation.
   */
  private function ordinary(Definition $definition, string $class, bool $overrides = FALSE): bool {
    if ($definition->getClass() !== $class || $definition->getFactory() !== NULL
      || $definition->getConfigurator() !== NULL || $definition->getDecoratedService() !== NULL
      || $definition->getProperties() !== [] || $definition->isLazy()
      || $definition->isSynthetic() || $definition->isAbstract() || !$definition->isShared()) {
      return FALSE;
    }
    foreach ($definition->getMethodCalls() as $call) {
      // Core installs runtime override providers this way. Their effective
      // values are captured separately; the host must contain those writers.
      if (!$overrides || $call[0] !== 'addOverride' || count($call[1]) !== 1
        || !$call[1][0] instanceof Reference) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
