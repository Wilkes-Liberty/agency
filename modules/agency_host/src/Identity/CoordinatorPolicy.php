<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Applies host-configured coordinator eligibility, without tenant role names.
 */
final class CoordinatorPolicy {

  public function __construct(private readonly ConfigFactoryInterface $config) {}

  /**
   * Rejects roles the host has listed or prefixed as ineligible.
   */
  public function hasDisallowedRole(AccountInterface $account): bool {
    $settings = $this->config->get('agency_host.settings');
    $ids = $settings->get('disallowed_role_ids') ?? [];
    $prefixes = $settings->get('disallowed_role_prefixes') ?? [];
    foreach ($account->getRoles() as $role) {
      if (in_array($role, $ids, TRUE)) {
        return TRUE;
      }
      foreach ($prefixes as $prefix) {
        if (is_string($prefix) && $prefix !== '' && str_starts_with($role, $prefix)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Rejects configured account names that cannot act as coordinators.
   */
  public function isProtectedAccount(UserInterface $user): bool {
    $names = $this->config->get('agency_host.settings')->get('protected_account_names') ?? [];
    return in_array($user->getAccountName(), $names, TRUE);
  }

}
