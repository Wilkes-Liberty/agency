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

  public function connection(): Connection;

  public function collection(): string;

  public function lookupDigest(string $digest, bool $lock = FALSE): ?InvitationRecord;

  public function insertDigest(InvitationRecord $record): void;

  /**
   * Conditionally consumes the exact locked snapshot; returns affected rows.
   */
  public function consume(InvitationRecord $record): int;

}
