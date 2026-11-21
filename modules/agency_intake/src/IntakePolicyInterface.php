<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Tenant-scoped intake policy port. Never returns a policy payload.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Missing and foreign forms share not_found. Malformed declarations
 * never fall back to a permissive catalog. Mail copy, authored locale
 * and notification language stay host.
 */
interface IntakePolicyInterface {

  /**
   * Whether a named action is allowed for one form.
   *
   * The answer is allow or deny. It is never a policy, recipient or
   * vendor mapping payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $form_id
   *   Opaque form identifier.
   * @param string $action
   *   Opaque action name.
   *
   * @return bool
   *   TRUE only when the action is allowed. Default implementation
   *   throws.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, or missing and foreign forms.
   */
  public function allows(
    IntakePrincipal $principal,
    string $form_id,
    string $action,
  ): bool;

  /**
   * Resolves trusted policy for one form.
   *
   * Default implementation never returns a payload. Host adapters
   * supply reviewed configuration.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $form_id
   *   Opaque form identifier.
   *
   * @return array{outcome: string}
   *   Found outcome without policy fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, or missing and foreign forms.
   */
  public function resolve(
    IntakePrincipal $principal,
    string $form_id,
  ): array;

}
