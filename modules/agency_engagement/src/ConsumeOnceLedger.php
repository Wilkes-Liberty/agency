<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * In-memory consume-once ledger for submission and statement keys.
 *
 * Caller-facing replay of a consumed or expired key is Discovery
 * not_found. A second financial claim is never issued. This is the
 * deny/replay/timeout oracle; cutover copy proofs stay G4.
 */
final class ConsumeOnceLedger {

  /**
   * Held or consumed keys.
   *
   * @var array<string, array{digest: string, expires_at: int, consumed: bool}>
   */
  private array $keys = [];

  /**
   * Records a key that has not yet committed a financial claim.
   */
  public function hold(string $key, string $digest, int $expires_at): void {
    $this->assertKey($key, $digest);
    $this->keys[$key] = [
      'digest' => $digest,
      'expires_at' => $expires_at,
      'consumed' => FALSE,
    ];
  }

  /**
   * Consumes a key or refuses a replay, mismatch or timeout.
   */
  public function consume(
    string $key,
    string $digest,
    int $now,
    int $expires_at,
  ): EngagementDecision {
    if ($key === '' || $digest === '') {
      return EngagementDecision::notFound();
    }
    if (str_contains($key, "\0") || str_contains($digest, "\0")) {
      return EngagementDecision::notFound();
    }
    $existing = $this->keys[$key] ?? NULL;
    if ($existing === NULL) {
      $this->keys[$key] = [
        'digest' => $digest,
        'expires_at' => $expires_at,
        'consumed' => TRUE,
      ];
      return EngagementDecision::provisioned();
    }
    if ($existing['consumed']) {
      return EngagementDecision::notFound();
    }
    if ($existing['expires_at'] < $now) {
      unset($this->keys[$key]);
      return EngagementDecision::notFound();
    }
    if ($existing['digest'] !== $digest) {
      return EngagementDecision::notFound();
    }
    $this->keys[$key]['consumed'] = TRUE;
    return EngagementDecision::provisioned();
  }

  /**
   * Refuses empty or NUL key material.
   */
  private function assertKey(string $key, string $digest): void {
    if ($key === '' || $digest === '') {
      throw new \InvalidArgumentException('invalid');
    }
    if (str_contains($key, "\0") || str_contains($digest, "\0")) {
      throw new \InvalidArgumentException('invalid');
    }
  }

}
