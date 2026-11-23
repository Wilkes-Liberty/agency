<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Fail-closed validation for injected intake-policy catalogs.
 *
 * Array in and array out. Callers inject a catalog or an explicit
 * declaration. Overlay catalogs and Webform third-party settings stay
 * host. This helper is not a runtime service and does not implement
 * IntakePolicyInterface. UnavailableIntakePolicy remains the hatch.
 */
final class IntakePolicy {

  /**
   * Policy used when a declaration is missing or malformed.
   */
  public const DEFAULT = [
    'purpose' => 'general',
    'auth' => 'public',
    'consent_basis' => '',
    'consent_version' => '',
    'actions' => ['store', 'notify'],
    'jira_request_type' => '',
    'crm_mode' => 'none',
    'notify_route' => '',
    'ack_template' => '',
    'analytics_mode' => 'none',
    'retention_class' => 'operator_review',
    'quarantine_eligible' => FALSE,
  ];

  /**
   * Rejects incomplete, mistyped and unsupported policy configurations.
   *
   * @param mixed $policy
   *   Untrusted policy declaration.
   *
   * @return bool
   *   TRUE only when every required key is present and allowed.
   *
   * @phpstan-assert-if-true array<string, mixed> $policy
   */
  public static function valid(mixed $policy): bool {
    if (!is_array($policy)
      || array_diff_key(self::DEFAULT, $policy)
      || array_diff_key($policy, self::DEFAULT)) {
      return FALSE;
    }
    foreach (self::DEFAULT as $key => $default) {
      if (gettype($policy[$key]) !== gettype($default)) {
        return FALSE;
      }
    }
    foreach ([
      'purpose' => [
        'general',
        'commercial',
        'recruiting',
        'staff',
        'security',
        'privacy',
      ],
      'auth' => ['public', 'authenticated', 'invited'],
      'crm_mode' => [
        'none',
        'contact_activity',
        'contact_activity_opportunity',
        'flag_opportunity',
        'activity_on_linked_only',
      ],
      'analytics_mode' => ['none', 'provenance'],
    ] as $key => $allowed) {
      if (!in_array($policy[$key], $allowed, TRUE)) {
        return FALSE;
      }
    }
    if (!array_is_list($policy['actions'])
      || !in_array('store', $policy['actions'], TRUE)) {
      return FALSE;
    }
    foreach ($policy['actions'] as $action) {
      if (!in_array($action, [
        'store',
        'notify',
        'acknowledge',
        'jira',
        'crm',
        'audit',
      ], TRUE)) {
        return FALSE;
      }
    }
    return ($policy['purpose'] === 'commercial'
      || ($policy['crm_mode'] === 'none'
        && !in_array('crm', $policy['actions'], TRUE)))
      && ($policy['purpose'] !== 'staff'
        || $policy['analytics_mode'] === 'none');
  }

  /**
   * Resolves trusted policy for one form from an injected catalog.
   *
   * An explicit declaration, including an empty array, replaces the
   * catalog. Malformed explicit values fail closed to DEFAULT and
   * never fall back to catalog[form_id].
   *
   * @param string $form_id
   *   Opaque form identifier.
   * @param mixed $explicit
   *   Caller-supplied declaration, or NULL to use the catalog.
   * @param array<array-key, mixed> $catalog
   *   Optional policies keyed by form id. Default empty.
   *
   * @return array<string, mixed>
   *   A valid policy, or DEFAULT when the declaration is unusable.
   */
  public static function resolve(
    string $form_id,
    mixed $explicit = NULL,
    array $catalog = [],
  ): array {
    if ($explicit !== NULL) {
      return self::valid($explicit) ? $explicit : self::DEFAULT;
    }
    $declared = $catalog[$form_id] ?? NULL;
    return is_array($declared) && self::valid($declared)
      ? $declared
      : self::DEFAULT;
  }

  /**
   * Permits a configured action, with a commercial-purpose boundary.
   *
   * @param string $form_id
   *   Opaque form identifier.
   * @param string $action
   *   Opaque action name.
   * @param mixed $explicit
   *   Caller-supplied declaration, or NULL to use the catalog.
   * @param array<array-key, mixed> $catalog
   *   Optional policies keyed by form id. Default empty.
   *
   * @return bool
   *   TRUE only when the resolved policy lists the action and CRM
   *   stays bound to commercial purpose with a non-none crm_mode.
   */
  public static function allows(
    string $form_id,
    string $action,
    mixed $explicit = NULL,
    array $catalog = [],
  ): bool {
    $policy = self::resolve($form_id, $explicit, $catalog);
    return in_array($action, $policy['actions'], TRUE)
      && ($action !== 'crm'
        || ($policy['purpose'] === 'commercial'
          && $policy['crm_mode'] !== 'none'));
  }

}
