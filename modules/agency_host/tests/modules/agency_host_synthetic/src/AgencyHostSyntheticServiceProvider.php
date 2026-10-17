<?php

declare(strict_types=1);

namespace Drupal\agency_host_synthetic;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Supplies identity and recording faults only in an isolated synthetic site.
 */
final class AgencyHostSyntheticServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (Settings::get('agency_synthetic_host') !== TRUE) {
      throw new \LogicException('Synthetic identity requires the isolated fixture settings.');
    }
    $container->setDefinition('agency_host_synthetic.recording_connection',
      new Definition(SyntheticRecordingConnection::class, [new Reference('database'), new Reference('state')]));
    $container->getDefinition('agency_staff.outbox')->replaceArgument(0, new Reference('agency_host_synthetic.recording_connection'));
    foreach (['agency_host.idp_directory', 'agency_host.access_token_validator'] as $id) {
      $container->getDefinition($id)->setClass(SyntheticIdentity::class)
        ->setArguments([new Reference('state')]);
    }
    if (Settings::get('agency_delivery_synthetic') === TRUE) {
      $definition = new Definition(SyntheticInvitationMail::class, [
        new Reference('agency_host_synthetic.invitation_mail.inner'), new Reference('state'),
      ]);
      $definition->setDecoratedService('agency_host.invitation_mail');
      $container->setDefinition('agency_host_synthetic.invitation_mail', $definition);
    }
  }

}
