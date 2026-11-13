<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Denies generic entity APIs until a later slice wires workforce policy.
 */
final class DenyAccess extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return AccessResult::forbidden()->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::forbidden()->setCacheMaxAge(0);
  }

}
