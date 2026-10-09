<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Fixtures;

use Drupal\agency_staff\Identity\IdentityProviderInterface;
use Drupal\agency_staff\Identity\Principal;
use Drupal\Core\Session\AccountInterface;

/**
 * Test-only trusted provider; never installed in a host container.
 */
final class DiscoveryIdentityFixture implements IdentityProviderInterface {

  /**
   * Current synthetic principal.
   */
  public ?Principal $principal;

  /**
   * Whether the adapter considers this a human coordinator.
   */
  public bool $eligible = TRUE;

  /**
   * Authoritative contact preference.
   */
  public bool $contactAllowed = TRUE;

  public function __construct() {
    $this->principal = new Principal('https://identity.example.test', 'person-one');
  }

  /**
   * {@inheritdoc}
   */
  public function current(): ?Principal {
    return $this->principal;
  }

  /**
   * {@inheritdoc}
   */
  public function intended(array $contact): ?Principal {
    return $this->principal;
  }

  /**
   * {@inheritdoc}
   */
  public function coordinatorEligible(AccountInterface $account): bool {
    return $this->eligible;
  }

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    return $this->contactAllowed;
  }

}
