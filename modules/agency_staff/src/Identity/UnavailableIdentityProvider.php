<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Identity;

use Drupal\Core\Session\AccountInterface;

/**
 * Refuses all authority until an installing host supplies a reviewed adapter.
 */
final class UnavailableIdentityProvider implements IdentityProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function current(): ?Principal {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function intended(array $contact): ?Principal {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function coordinatorEligible(AccountInterface $account): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    return FALSE;
  }

}
