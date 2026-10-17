<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

/**
 * Machine-readable shadow comparison with a pass/fail and explained exceptions.
 */
final class ReconciliationReport {

  /**
   * Constructs a report from counted totals, digests and exceptions.
   *
   * @param array<string, int> $counts
   *   Source, target and matched counts.
   * @param array<int|string, array<string, array<string, string>>> $field_digests
   *   Per-id, per-field source and target digests.
   * @param list<array<string, mixed>> $exceptions
   *   Explained failures.
   */
  public function __construct(
    private readonly array $counts,
    private readonly array $field_digests,
    private readonly array $exceptions,
  ) {}

  /**
   * TRUE when no exceptions were recorded.
   */
  public function passed(): bool {
    return $this->exceptions === [];
  }

  /**
   * Explained failures.
   *
   * @return list<array<string, mixed>>
   *   Exception records.
   */
  public function exceptions(): array {
    return $this->exceptions;
  }

  /**
   * Encodes the report for operators and host automation.
   *
   * @return array<string, mixed>
   *   JSON-serializable report.
   */
  public function toArray(): array {
    return [
      'passed' => $this->passed(),
      'counts' => $this->counts,
      'field_digests' => $this->field_digests,
      'exceptions' => $this->exceptions,
    ];
  }

}
