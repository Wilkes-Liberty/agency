<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Durable invalidation independent of a rejected business transaction.
 */
interface QualificationLatchInterface {

  /**
   * Arms a durable guard before observing the authority snapshot.
   */
  public function arm(string $epoch): string;

  /**
   * Resolves only the caller's clean verification guard.
   */
  public function resolve(string $guard): void;

  /**
   * Permanently invalidates one qualification epoch.
   */
  public function invalidate(string $epoch): void;

  /**
   * Checks the primary store; unavailable storage must throw.
   */
  public function invalidated(string $epoch): bool;

}
