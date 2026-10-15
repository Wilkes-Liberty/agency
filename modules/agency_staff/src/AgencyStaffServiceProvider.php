<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\DependencyInjection\RetentionPolicyWiringPass;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;

/**
 * Checks the policy backend after core resolves backend overrides and aliases.
 */
final class AgencyStaffServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new RetentionPolicyWiringPass(), PassConfig::TYPE_BEFORE_REMOVING, PHP_INT_MIN);
  }

}
