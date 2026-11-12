<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\Core\Database\Connection;

/**
 * Compatibility storage on the caller's exact database connection.
 *
 * Digests are already SHA-256 keys. Never hash them again or renew expiry.
 */
interface InvitationStoreInterface {

  /**
   * Returns the exact connection participating in the caller transaction.
   */
  public function connection(): Connection;

  /**
   * Returns the canonical owning collection without changing source identity.
   */
  public function collection(): string;

  /**
   * Freshly reads owning SQL storage; never hash again or renew expiry.
   *
   * A locked lookup must read through to storage, without an adapter cache.
   *
   * @phpstan-impure
   */
  public function lookupDigest(string $digest, bool $lock = FALSE): ?InvitationRecord;

  /**
   * Stores a new digest snapshot with its absolute expiry.
   */
  public function insertDigest(InvitationRecord $record): void;

  /**
   * Conditionally consumes the exact locked snapshot; returns affected rows.
   *
   * @phpstan-impure
   */
  public function consume(InvitationRecord $record): int;

}
