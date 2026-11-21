<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Tenant-scoped intake outbox port. Never performs remote work.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Missing and foreign intents share not_found. Vendor transport stays
 * a host adapter.
 */
interface IntakeOutboxInterface {

  /**
   * Records one receipt's supported actions without remote work.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, id: string}
   *   Queued outcome without payload, dispatch key or vendor fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, missing id, or foreign tenant.
   */
  public function enqueue(IntakePrincipal $principal, string $id): array;

  /**
   * Processes one recorded action after the receipt transaction commits.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   * @param string $action
   *   Opaque action name. Default implementation never executes it.
   *
   * @return array{outcome: string, id: string}
   *   Processed outcome without payload or vendor fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, missing id, foreign tenant, or denied action.
   */
  public function process(
    IntakePrincipal $principal,
    string $id,
    string $action,
  ): array;

}
