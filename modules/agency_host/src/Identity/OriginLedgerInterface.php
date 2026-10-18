<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Independent, permanent issuer/receipt consumption for initial qualification.
 */
interface OriginLedgerInterface {

  /**
   * Reads a previously consumed issuer, without granting current authority.
   */
  public function find(string $issuerKey): ?array;

  /**
   * Reads committed state through a new primary connection after uncertainty.
   */
  public function readCommitted(string $issuerKey): ?array;

  /**
   * Atomically consumes an origin and arms its owned qualification guard.
   */
  public function reserve(array $record): void;

  /**
   * Completes an exact reservation and removes only its guard atomically.
   *
   * The caller holds the full writer fence after confirmed coverage commit.
   */
  public function complete(array $record, int $completed): void;

}
