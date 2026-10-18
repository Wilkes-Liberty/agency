<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Replaces Staff's unavailable identity adapter after YAML definitions merge.
 */
final class AgencyHostServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->setAlias('agency_staff.identity', 'agency_host.identity')->setPublic(TRUE);
  }

}
