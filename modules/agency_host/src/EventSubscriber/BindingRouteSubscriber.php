<?php

declare(strict_types=1);

namespace Drupal\agency_host\EventSubscriber;

use Drupal\agency_host\Form\TrackedAuthmapDeleteForm;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes Externalauth admin deletion through the tracked public write boundary.
 */
final class BindingRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('externalauth.authmap_delete_form')) {
      $route->setDefault('_form', TrackedAuthmapDeleteForm::class);
    }
  }

}
