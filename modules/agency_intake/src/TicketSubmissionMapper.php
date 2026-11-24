<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Maps ticket intake without copying unrelated private fields.
 *
 * Accepts submitted values and a submission id so unit tests construct
 * the mapper without Webform. JSM option ids and customfield keys stay
 * host. Priority is always Medium.
 */
final class TicketSubmissionMapper {

  /**
   * Public ticket fields permitted in the private service request.
   */
  private const FIELD_ALLOWLIST = [
    'source_url',
    'referring_page',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_term',
    'utm_content',
    'consent_version',
    'utm_id',
    'entry_source',
    'entry_cta',
    'entry_intent',
    'entry_platform',
    'form_version',
    'received_at',
    'submission_language',
    'summary',
    'problem',
    'systems_involved',
    'impact',
    'urgency',
    'constraints',
    'desired_outcome',
    'first_name',
    'last_name',
    'work_email',
    'phone',
    'job_title',
    'organization_name',
    'existing_client',
    'service_consent',
  ];

  /**
   * Builds a request from submitted values and a durable identifier.
   *
   * @param array<string, mixed> $values
   *   Submitted values after validation.
   * @param string|int $submission_id
   *   Durable submission identifier.
   *
   * @return array<string, mixed>
   *   Delivery payload without private fields or vendor option ids.
   */
  public function map(array $values, string|int $submission_id): array {
    $fields = array_intersect_key($values, array_flip(self::FIELD_ALLOWLIST));
    $text = static fn(string $key): string =>
      is_string($fields[$key] ?? NULL) ? $fields[$key] : '';
    $summary = mb_substr(
      trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text('summary')) ?? ''),
      0,
      200,
    );
    if ($summary === '') {
      $summary = 'Open a ticket';
    }

    return [
      'external_reference' => 'ticket-' . $submission_id,
      'requester_email' => $text('work_email'),
      'summary' => $summary,
      'route' => 'ticket',
      'recommendation' => 'operator_review',
      'recommendation_reason' => 'Public ticket intake.',
      'response_due' => 'operator_review',
      'fields' => $fields,
      'request_field_values' => [
        'priority' => ['name' => 'Medium'],
      ],
    ];
  }

}
