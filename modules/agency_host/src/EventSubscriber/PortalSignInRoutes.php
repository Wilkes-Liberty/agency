<?php

declare(strict_types=1);

namespace Drupal\agency_host\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds portal containment without replacing contrib's state and issuer checks.
 */
final class PortalSignInRoutes extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['openid_connect.redirect_controller_redirect', 'openid_connect.redirect_controller_redirect_iss'] as $name) {
      $collection->get($name)?->setRequirement('_agency_host_portal_sign_in', 'TRUE');
    }
  }

}
