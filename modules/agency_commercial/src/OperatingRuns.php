<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Bounded operating-seat names, lease limits and failure codes.
 *
 * These are work-queue seats, not portal roles and not ToolOperations
 * finance, marketing or outbound seats. Live claim storage stays host.
 */
final class OperatingRuns {

  /**
   * Dedicated operating seats. Unknown names resolve to no actions.
   */
  public const SEATS = ['steward', 'intake', 'delivery', 'renewal'];

  /**
   * Maximum claim attempts before a run is failed closed.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * Claim lease lifetime in seconds.
   */
  public const LEASE_SECONDS = 600;

  /**
   * Public failure codes. Unknown codes fail closed.
   */
  public const FAILURE_CODES = [
    'timeout',
    'invalid_output',
    'runner_unavailable',
    'source_denied',
    'model_error',
  ];

  /**
   * Lists lifecycle actions the named operating seat may invoke.
   *
   * @param string $seat
   *   Operating seat name.
   *
   * @return list<string>
   *   Action names, or empty when the seat is unknown.
   */
  public static function forSeat(string $seat): array {
    if (!in_array($seat, self::SEATS, TRUE)) {
      return [];
    }
    return ['claim', 'context', 'submit', 'fail'];
  }

}
