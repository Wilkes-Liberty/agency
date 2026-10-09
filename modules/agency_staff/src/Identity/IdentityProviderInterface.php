<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Identity;

use Drupal\Core\Session\AccountInterface;

/**
 * Trusted host identity port; unavailable is the package's default.
 */
interface IdentityProviderInterface {

  /**
   * Revalidates the effective session, deactivation and subject rebinding.
   */
  public function current(): ?Principal;

  /**
   * Resolves a verified intended subject, not an email-equality match.
   */
  public function intended(array $contact): ?Principal;

  /**
   * Attests an eligible human credential, refusing machine and mixed scopes.
   */
  public function coordinatorEligible(AccountInterface $account): bool;

  /**
   * Checks the authoritative contact preference before preparing delivery.
   */
  public function mayContact(array $contact): bool;

}
