<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects declaration metadata without instantiating host participants.
 */
final class AgencyMigrateServiceProvider implements ServiceProviderInterface, CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass($this);
  }

  /**
   * {@inheritdoc}
   */
  public function process(SymfonyContainerBuilder $container): void {
    $registry = $container->getDefinition('agency_migrate.rollback_dispositions');
    foreach ($container->findTaggedServiceIds('agency_migrate.rollback_disposition') as $service_id => $tags) {
      $class = $container->getParameterBag()->resolveValue($container->getDefinition($service_id)->getClass());
      if (count($tags) !== 1 || !is_string($class) || !is_a($class, RollbackDispositionInterface::class, TRUE)) {
        throw new LogicException('A rollback disposition service must implement its interface and declare exactly one tag.');
      }
      $registry->addMethodCall('addProvider', [
        new ServiceClosureArgument(new Reference($service_id)),
        $tags[0]['provider_id'] ?? NULL,
        $tags[0]['domain'] ?? NULL,
      ]);
    }
  }

}
