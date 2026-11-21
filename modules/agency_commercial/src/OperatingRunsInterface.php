<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Tenant-scoped operating-run hatch. Never dumps a run payload.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Missing and foreign runs share not_found. The hatch never claims,
 * leases or writes a draft.
 */
interface OperatingRunsInterface {

  /**
   * Claims one due run for the hatch tenant.
   *
   * Default implementation never returns a lease or routine.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   *
   * @return array{outcome: string}
   *   Found outcome without lease, draft or source fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, or no visible run.
   */
  public function claim(CommercialPrincipal $principal): array;

  /**
   * Binds a claimed run to one disclosed context.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $run_id
   *   Opaque run identifier.
   * @param string $lease
   *   Opaque lease token.
   *
   * @return array{outcome: string}
   *   Found outcome without source fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, missing run, or foreign tenant.
   */
  public function context(
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
  ): array;

  /**
   * Submits a bounded draft for a claimed run.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $run_id
   *   Opaque run identifier.
   * @param string $lease
   *   Opaque lease token.
   * @param array<string, mixed> $draft
   *   Candidate recommendation. Default implementation never writes.
   *
   * @return array{outcome: string}
   *   Found outcome without draft fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, missing run, or foreign tenant.
   */
  public function submit(
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
    array $draft,
  ): array;

  /**
   * Records an allowlisted failure without exception or prompt text.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $run_id
   *   Opaque run identifier.
   * @param string $lease
   *   Opaque lease token.
   * @param string $failure_code
   *   Allowlisted public failure code.
   *
   * @return array{outcome: string}
   *   Found outcome without failure payload.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, missing run, or foreign tenant.
   */
  public function fail(
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
    string $failure_code,
  ): array;

}
