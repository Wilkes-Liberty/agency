<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Validates a participant access token and returns its subject.
 */
interface AccessTokenValidatorInterface {

  /**
   * Whether issuer, audience, and JWT support are present to verify.
   *
   * False is operator setup, not a bad visitor token.
   */
  public function isConfigured(): bool;

  /**
   * Returns the token's sub when the JWT is acceptable.
   *
   * Fail closed: signature, issuer, audience, expiry, and a non-empty sub
   * are all required. Does not provision an account.
   *
   * @return string|null
   *   The subject claim, or NULL when the token is not acceptable.
   */
  public function validate(string $jwt): ?string;

}
