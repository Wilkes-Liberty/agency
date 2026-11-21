<?php

declare(strict_types=1);

namespace Drupal\agency_intake\Exception;

/**
 * Carries an allowlisted public reason without intake payload.
 */
final class IntakeRefusal extends \RuntimeException {

  /**
   * Missing or foreign intake records share this reason.
   */
  public const NOT_FOUND = 'not_found';

  /**
   * Hatch token without a usable tenant claim.
   */
  public const MISSING_TENANT_CLAIM = 'missing_tenant_claim';

  /**
   * The other domain's authority is not granted by this action.
   */
  public const ROLE_ABSENT = 'role_absent';

  /**
   * Stable refusal reason, without diagnostic payload.
   */
  public readonly string $reason;

  /**
   * Constructs a payload-free intake refusal.
   *
   * @param string $reason
   *   Allowlisted public reason.
   */
  public function __construct(string $reason) {
    $this->reason = $reason;
    parent::__construct($reason);
  }

  /**
   * Returns the payload-free public refusal body.
   *
   * @return array{outcome: string, reason: string}
   *   Refused outcome and this reason.
   */
  public function outcome(): array {
    return [
      'outcome' => 'refused',
      'reason' => $this->reason,
    ];
  }

}
