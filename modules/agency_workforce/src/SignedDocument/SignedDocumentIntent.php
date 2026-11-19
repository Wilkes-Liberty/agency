<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\SignedDocument;

/**
 * Immutable preconditions and proposed changes for a qualified operation.
 *
 * An append intent ends at lease acquisition. Completion requires a separately
 * qualified writer with lease-holder fencing, file disposition and audit.
 *
 * @internal
 */
final readonly class SignedDocumentIntent {

  /**
   * Keeps the exact expected mapping and original pins for a future recheck.
   *
   * @param string $operation
   *   The qualified decision.
   * @param \Drupal\agency_workforce\SignedDocument\MappingSnapshot $expected
   *   The exact source row precondition.
   * @param list<string> $states
   *   Candidate state changes in order.
   * @param string|null $retryKey
   *   Verification retry key.
   * @param string|null $digest
   *   SHA-256 of the matched document.
   * @param int|null $signedAt
   *   Matched provider signing time.
   * @param int|null $signerCount
   *   Matched provider signer count.
   * @param int|null $leaseUntil
   *   Proposed attach lease expiry.
   * @param string|null $appendField
   *   Class-specific User field.
   * @param int|null $appendDelta
   *   Proposed destination delta.
   * @param list<array{target_id: int, target_revision_id: int}> $existingPins
   *   User field items retained ahead of a proposed append.
   */
  public function __construct(
    public string $operation,
    public MappingSnapshot $expected,
    public array $states,
    public ?string $retryKey = NULL,
    public ?string $digest = NULL,
    public ?int $signedAt = NULL,
    public ?int $signerCount = NULL,
    public ?int $leaseUntil = NULL,
    public ?string $appendField = NULL,
    public ?int $appendDelta = NULL,
    public array $existingPins = [],
  ) {}

  /**
   * Reports the effective slot after the proposed state change.
   */
  public function openSlot(): ?string {
    $state = $this->states[array_key_last($this->states)] ?? $this->expected->row['state'];
    return in_array($state, MappingSnapshot::TERMINAL_STATES, TRUE) ? NULL : $this->expected->row['open_slot'];
  }

}
