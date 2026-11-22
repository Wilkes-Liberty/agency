<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies an internal proxy suspicion signal against the request body.
 *
 * Header names are package-owned. Host adapters may translate a tenant
 * ceremony onto these names before calling trusted().
 */
final class IntakeProxySignal {

  /**
   * Timestamped suspicion classification header.
   */
  public const SIGNAL_HEADER = 'X-Agency-Intake-Signal';

  /**
   * HMAC of the signal line plus the exact request body.
   */
  public const SIGNATURE_HEADER = 'X-Agency-Intake-Signature';

  /**
   * Confirms a fresh signature bound to the exact body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Incoming request whose body must match the signature.
   * @param string $secret
   *   Shared proxy secret. Empty secrets never trust a signal.
   * @param int $now
   *   Unix time used to bound signal age to sixty seconds.
   *
   * @return bool
   *   TRUE only when the header pair is present, fresh and bound.
   */
  public static function trusted(Request $request, string $secret, int $now): bool {
    $signal = $request->headers->get(self::SIGNAL_HEADER, '');
    $fresh = preg_match('/^([0-9]+):suspected$/D', $signal, $parts)
      && abs($now - (int) $parts[1]) <= 60;
    if ($secret === '' || !$fresh) {
      return FALSE;
    }
    $expected = hash_hmac(
      'sha256',
      $signal . "\n" . $request->getContent(),
      $secret,
    );
    return hash_equals(
      $expected,
      $request->headers->get(self::SIGNATURE_HEADER, ''),
    );
  }

}
