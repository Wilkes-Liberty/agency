<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Ratified commercial vocabulary, shared by forms, validation and tools.
 */
final class Vocabulary {

  /**
   * Allowed opportunity source keys and English labels.
   */
  public const SOURCES = [
    'referral' => 'Referral',
    'inbound' => 'Inbound',
    'outbound' => 'Outbound',
    'partner' => 'Partner',
    'federal_portal' => 'Federal portal',
    'other' => 'Other',
  ];

  /**
   * Allowed opportunity stage keys and English labels.
   */
  public const STAGES = [
    'inbound' => 'Inbound',
    'qualified' => 'Qualified',
    'discovery' => 'Discovery',
    'proposal' => 'Proposal',
    'negotiation' => 'Negotiation',
    'sow' => 'SOW',
    'won' => 'Won',
    'lost' => 'Lost',
    'parked' => 'Parked',
  ];

  /**
   * Allowed contact-restriction keys and English labels.
   */
  public const RESTRICTIONS = [
    'unspecified' => 'Unspecified',
    'contact_allowed' => 'Contact allowed',
    'do_not_contact' => 'Do not contact',
  ];

  /**
   * Allowed activity type keys and English labels.
   */
  public const ACTIVITIES = [
    'note' => 'Note',
    'meeting' => 'Meeting',
    'call' => 'Call',
    'email_meta' => 'Email metadata',
  ];

  /**
   * Searchable commercial entity type IDs that exist on this package.
   *
   * Agency has no contact entity type. Do not list one.
   */
  public const TYPES = [
    'agency_opportunity',
    'agency_activity',
  ];

  /**
   * Defers interface translation without changing persisted option keys.
   *
   * @param array<string, string> $labels
   *   Machine names mapped to English labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The same keys with translatable labels.
   */
  public static function options(array $labels): array {
    // Owned labels are maintained in the committed interface catalogs.
    return array_map(
      static function (string $label): TranslatableMarkup {
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        return new TranslatableMarkup($label);
      },
      $labels,
    );
  }

}
