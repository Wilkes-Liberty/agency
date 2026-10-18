<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Input for inviteStaff. Capture is not sending and mints no host account.
 */
final class InviteStaff {

  /**
   * Display name stored on the person resolver.
   */
  public readonly string $displayName;

  /**
   * Typed contact route; never used to select a principal.
   */
  public readonly array $contact;

  /**
   * Issuer-supplied workforce kind.
   */
  public readonly StaffClassification $classification;

  public function __construct(
    string $displayName,
    array $contact,
    StaffClassification $classification,
  ) {
    $this->displayName = WorkforceInput::text($displayName, 255);
    $this->contact = WorkforceInput::contact($contact);
    $this->classification = $classification;
  }

  /**
   * Validates coordinator-supplied invitation input.
   */
  public static function fromArray(array $input): self {
    WorkforceInput::onlyKeys($input, [
      'display_name',
      'contact',
      'classification',
    ]);
    $contact = $input['contact'] ?? NULL;
    if (!is_array($contact)) {
      throw new Refusal('invalid');
    }
    $classification = $input['classification'] ?? '';
    if (!is_string($classification)) {
      throw new Refusal('invalid');
    }
    return new self(
      WorkforceInput::text($input['display_name'] ?? '', 255),
      $contact,
      new StaffClassification($classification),
    );
  }

}
