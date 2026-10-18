<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * File Gate UUID only; profile evidence never carries file bytes.
 */
final class ProtectedFileRef {

  /**
   * File Gate object UUID.
   */
  public readonly string $uuid;

  public function __construct(string $uuid) {
    $this->uuid = WorkforceInput::uuid($uuid);
  }

  /**
   * Refuses inline bodies, data URIs and remote file payloads.
   */
  public static function fromUnknown(mixed $value): self {
    if (is_array($value)) {
      WorkforceInput::onlyKeys($value, ['uuid']);
      if (!array_key_exists('uuid', $value)) {
        throw new Refusal('invalid');
      }
      return new self(WorkforceInput::uuid($value['uuid']));
    }
    if (!is_string($value) || str_starts_with($value, 'data:') || str_contains($value, '://')) {
      throw new Refusal('invalid');
    }
    return new self($value);
  }

}
