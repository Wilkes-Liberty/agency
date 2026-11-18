<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

/**
 * Stable qualification refusal with a payload-free access outcome.
 */
final class PersonnelFileRefusal extends \RuntimeException {

  /**
   * The operator-visible reason.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = in_array($reason, [
      'not_found', 'stale_source', 'unmapped_property', 'unqualified_file',
      'file_projection_drift',
    ], TRUE) ? $reason : 'not_found';
    parent::__construct($this->reason);
  }

  /**
   * Returns the common payload-free application refusal.
   *
   * @return array{outcome: string, reason: string}
   *   The refusal for a governed application boundary.
   */
  public function outcome(): array {
    return ['outcome' => 'refused', 'reason' => 'not_found'];
  }

}
