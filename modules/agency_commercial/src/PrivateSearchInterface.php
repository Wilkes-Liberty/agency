<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Tenant-scoped private-search hatch. Never dumps query or field text.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Missing and foreign hits share not_found. Typesense, Solr and live
 * indexes stay host.
 */
interface PrivateSearchInterface {

  /**
   * Looks up records for one opaque query.
   *
   * Default implementation never returns hits or echoes the query.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $query
   *   Opaque search text. Must not appear on a public refusal.
   *
   * @return array{outcome: string}
   *   Found outcome without hits, query or account fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, or no visible hit.
   */
  public function lookup(CommercialPrincipal $principal, string $query): array;

  /**
   * Filters records by opaque criteria.
   *
   * Default implementation never returns hits or account numbers.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array<string, mixed> $criteria
   *   Opaque filter criteria. Must not appear on a public refusal.
   *
   * @return array{outcome: string}
   *   Found outcome without hits or account fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, or no visible hit.
   */
  public function filter(
    CommercialPrincipal $principal,
    array $criteria,
  ): array;

}
