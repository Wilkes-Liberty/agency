<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\Core\Database\Connection;

/**
 * A local transaction participant; never commit, send mail or create a session.
 */
interface TransactionalStaffProvisionerInterface {

  /**
   * Returns the exact connection participating in the caller transaction.
   */
  public function connection(): Connection;

  /**
   * Checks case-insensitive account collisions under contact exclusion.
   */
  public function accountExists(string $normalizedContact): bool;

  /**
   * Creates the local account/profile and returns an opaque host binding.
   */
  public function provision(string $normalizedContact, string $classification, string $language, string $preferredName): string;

  /**
   * Freshly verifies the local account and matching profile on this connection.
   *
   * The opaque binding must identify that contact/classification/language.
   * Do not resolve remote identities or grant permissions during verification.
   */
  public function verifyBinding(string $binding, string $normalizedContact, string $classification, string $language): bool;

}
