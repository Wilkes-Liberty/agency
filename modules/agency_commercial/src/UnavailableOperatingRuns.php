<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default operating-run port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Leases, drafts and source context are never
 * dumped. The hatch never claims a live run.
 */
final class UnavailableOperatingRuns implements OperatingRunsInterface {

  /**
   * {@inheritdoc}
   */
  public function claim(CommercialPrincipal $principal): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function context(
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
    array $draft,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function fail(
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
    string $failure_code,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

}
