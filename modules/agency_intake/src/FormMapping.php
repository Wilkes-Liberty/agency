<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Resolves explicitly configured form IDs to supported payload contracts.
 *
 * Overlay PHP stays host. An empty map is the default; unsupported form
 * ids fail closed.
 */
final class FormMapping {

  /**
   * Mapper contracts this package will copy.
   */
  private const CONTRACTS = [
    'inquiry',
    'account',
  ];

  /**
   * Constructs a mapping from injected form ids.
   *
   * @param array<string, string> $mappings
   *   Form ids mapped to inquiry or account. Default empty.
   *
   * @throws \InvalidArgumentException
   *   When a form id or contract is not a supported mapper binding.
   */
  public function __construct(
    private readonly array $mappings = [],
  ) {
    foreach ($mappings as $id => $mapper) {
      if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]*$/D', $id)
        || !in_array($mapper, self::CONTRACTS, TRUE)) {
        throw new \InvalidArgumentException(
          'Intake mappings require a form ID and a supported mapper '
          . 'contract.',
        );
      }
    }
  }

  /**
   * Returns a mapper contract, or NULL when this form is not bound.
   *
   * @param string $form_id
   *   Saved form identifier.
   *
   * @return string|null
   *   inquiry or account when configured; NULL when unbound.
   */
  public function forForm(string $form_id): ?string {
    return $this->mappings[$form_id] ?? NULL;
  }

}
