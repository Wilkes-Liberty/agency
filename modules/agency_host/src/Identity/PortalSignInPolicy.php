<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Enforces configured OpenID Connect containment at sign-in boundaries.
 */
final class PortalSignInPolicy {

  public function __construct(
    private readonly ConfigFactoryInterface $config,
    private readonly StorageInterface $activeConfig,
  ) {}

  /**
   * Existing contained-client configuration must effectively permit the ceremony.
   */
  public function allows(string $provider): bool {
    $contained = $this->config->get('agency_host.settings')->get('contained_openid_clients') ?? [];
    if (!in_array($provider, $contained, TRUE)) {
      return TRUE;
    }
    $name = 'openid_connect.client.' . $provider;
    $stored = $this->activeConfig->read($name);
    $effective = $this->config->reset($name)->get($name);
    return is_array($stored) && $effective->get('status') === TRUE;
  }

}
