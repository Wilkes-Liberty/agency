<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Maps inquiry and account intake without copying unrelated private fields.
 *
 * Accepts submitted values and a form id so unit tests construct the
 * mapper without Webform. Private tokens, résumé values and unbound
 * staff or hiring forms fail closed.
 */
final class BusinessSubmissionMapper {

  /**
   * Attribution and consent keys shared by inquiry and account contracts.
   */
  private const COMMON_FIELDS = [
    'first_name',
    'last_name',
    'phone',
    'job_title',
    'country',
    'service_consent',
    'marketing_consent',
    'source_url',
    'referring_page',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_content',
    'utm_term',
    'utm_id',
    'entry_source',
    'entry_cta',
    'entry_intent',
    'entry_platform',
    'form_version',
    'consent_version',
    'received_at',
    'submission_language',
  ];

  /**
   * Resolved form-id contracts.
   */
  private readonly FormMapping $formMapping;

  /**
   * Constructs a mapper from injected form-id contracts.
   *
   * @param array<string, string> $formMappings
   *   Form ids mapped to inquiry or account. Default empty.
   *
   * @throws \InvalidArgumentException
   *   When a form id or contract is not a supported mapper binding.
   */
  public function __construct(array $formMappings = []) {
    $this->formMapping = new FormMapping($formMappings);
  }

  /**
   * Builds a request from the saved, validated form contract.
   *
   * @param string $form_id
   *   Saved form identifier.
   * @param array<string, mixed> $values
   *   Submitted values after validation.
   * @param string|int $submission_id
   *   Durable submission identifier.
   *
   * @return array<string, mixed>
   *   Delivery payload without private_token or resume.
   *
   * @throws \InvalidArgumentException
   *   When the form id is unbound or not an inquiry or account contract.
   */
  public function map(
    string $form_id,
    array $values,
    string|int $submission_id,
  ): array {
    $mapper = $this->formMapping->forForm($form_id);
    $specific = match ($mapper) {
      'inquiry' => [
        'intent',
        'platform',
        'email',
        'organization',
        'message',
        'subject',
      ],
      'account' => [
        'work_email',
        'organization_name',
        'why',
        'terms_consent',
      ],
      default => throw new \InvalidArgumentException(
        'Unsupported business intake form.',
      ),
    };
    $fields = array_intersect_key(
      $values,
      array_flip([...self::COMMON_FIELDS, ...$specific]),
    );
    $is_inquiry = $mapper === 'inquiry';
    $text = static fn(string $key, string $default): string =>
      is_string($fields[$key] ?? NULL) ? $fields[$key] : $default;
    $organization_name = $text('organization_name', 'Unknown organization');
    $summary = $is_inquiry
      ? $text('subject', 'Business inquiry')
      : 'Account access request — ' . $organization_name;
    $summary = mb_substr(
      trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $summary) ?? ''),
      0,
      200,
    );
    $email_key = $is_inquiry ? 'email' : 'work_email';

    return [
      'external_reference' => $form_id . '-' . $submission_id,
      'requester_email' => $text($email_key, ''),
      'summary' => $summary ?: 'Website request',
      'route' => $form_id,
      'recommendation' => 'operator_review',
      'recommendation_reason' => $is_inquiry
        ? 'Business inquiry; preserve the submitted intent and platform.'
        : 'Review account eligibility; receipt does not grant access.',
      'response_due' => 'operator_review',
      'fields' => $fields,
    ];
  }

}
