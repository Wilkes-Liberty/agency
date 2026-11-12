<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

/**
 * Host-owned exclusion and writer-policy coverage for an authority change.
 *
 * A positive adapter must cover every source and target writer, including raw
 * SQL and independent processes. Cooperative locks alone do not prove coverage.
 * It must keep the rejected side read-only after the authority change commits.
 * No positive production implementation ships with Agency.
 */
interface HostWriterFenceInterface {

  /**
   * Refuses unavailable coverage without writes, locks or reservations.
   */
  public function assertAvailable(string $domain): void;

  /**
   * Excludes covered writers until the caller's root transaction completes.
   *
   * The caller already holds the operational authority row in its transaction.
   * The adapter must acquire real source and target exclusion before returning,
   * retain it through root commit or rollback (not a savepoint release), and
   * tolerate nested acquisition on the same connection. Failure must throw.
   * The host must couple its normal writes and replay log to the same boundary.
   */
  public function acquire(string $domain): void;

}
