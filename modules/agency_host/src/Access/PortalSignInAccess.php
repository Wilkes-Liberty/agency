<?php

declare(strict_types=1);

namespace Drupal\agency_host\Access;

use Drupal\agency_host\Identity\PortalSignInPolicy;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\openid_connect\OpenIDConnectClientEntityInterface;

/**
 * Checks the resolved provider, including provider-slug route aliases.
 */
final class PortalSignInAccess implements AccessInterface {

  public function __construct(private readonly PortalSignInPolicy $policy) {}

  /**
   * Refuses disabled portal initiation and outstanding callback state.
   */
  public function access(OpenIDConnectClientEntityInterface $openid_connect_client): AccessResultInterface {
    return AccessResult::allowedIf($this->policy->allows($openid_connect_client->id()))
      ->setCacheMaxAge(0);
  }

}
