<?php

declare(strict_types=1);

namespace Drupal\agency_host\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\UserSession;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admits only the configured client-credentials BFF OAuth consumer.
 */
final class BffAccess implements ContainerInjectionInterface {

  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly ConfigFactoryInterface $config,
    private readonly ExplicitPermissionChecker $permissions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('field_guard.explicit_permission_checker'),
    );
  }

  /**
   * Refuses user-bound tokens and every consumer except the configured BFF.
   */
  public function access(): AccessResultInterface {
    $settings = $this->config->get('agency_host.settings');
    $client_id = trim((string) $settings->get('bff_client_id'));
    $principal = $this->account->getAccount();
    $allowed = $settings->get('enabled') === TRUE
      && $client_id !== ''
      && $principal instanceof TokenAuthUserInterface
      && $principal->getToken()->get('auth_user_id')->isEmpty()
      && hash_equals($client_id, (string) $principal->getConsumer()->get('client_id')->value)
      && $this->account->hasPermission('use agency discovery participant channel')
      && $this->permissions->hasExplicitPermission(
        new UserSession(['roles' => $principal->getToken()->getRoles()]),
        'use agency discovery participant channel',
      );
    return AccessResult::allowedIf($allowed)
      ->setCacheMaxAge(0)
      ->addCacheContexts(['user'])
      ->addCacheableDependency($settings);
  }

}
