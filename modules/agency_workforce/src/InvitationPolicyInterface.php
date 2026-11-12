<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

/**
 * Trusted host scope, issuer authority, duration and configured languages.
 */
interface InvitationPolicyInterface {

  public function scope(): string;

  public function assertIssuerAllowed(): void;

  /**
   * Clamps requested duration using the installing host's configured bounds.
   */
  public function duration(?int $requested): int;

  /**
   * Resolves NULL to configured default without rewriting old stored records.
   */
  public function language(?string $storedOrRequested): string;

  /**
   * Whether the current trusted operator may reconcile without a bearer.
   */
  public function canReconcile(): bool;

}
