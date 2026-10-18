<?php

declare(strict_types=1);

namespace Drupal\agency_host\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\externalauth\AuthmapInterface;

/**
 * Covers the actual OIDC provider lifecycle despite its legacy prefix typo.
 */
final class BindingHistoryHooks {

  public function __construct(private readonly AuthmapInterface $authmap) {}

  /**
   * Implements hook_entity_predelete().
   */
  #[Hook('entity_predelete')]
  public function preDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'openid_connect_client') {
      $this->authmap->deleteProvider('openid_connect.' . $entity->id());
    }
  }

}
