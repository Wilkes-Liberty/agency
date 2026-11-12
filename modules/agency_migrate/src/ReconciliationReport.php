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
   * @param array<string, mixed> $counts
   *   Source, target and matched counts, validated before full qualification.
   * @param array<int|string, array<string, array<string, string>>> $field_digests
   *   Per-id, per-field source and target digests.
   * @param list<array<string, mixed>> $exceptions
   *   Explained failures.
   * @param array<string, mixed> $coverage
   *   Optional full or sample coverage evidence. Omission retains full scope.
   */
  public function __construct(
    private readonly array $counts,
    private readonly array $field_digests,
    private readonly array $exceptions,
    private readonly array $coverage = [],
  ) {}

  /**
   * Whether the requested comparison scope passed, including full coverage.
   */
  public function passed(): bool {
    return ($this->coverage()['scope'] === 'sample')
      ? $this->exceptions === []
      : $this->fullPassed();
  }

  /**
   * Whether a complete population comparison passed; samples never qualify.
   */
  public function fullPassed(): bool {
    $coverage = $this->coverage();
    return $this->exceptions === []
      && $coverage['scope'] === 'full'
      && $coverage['complete'] === TRUE
      && $this->countsMatch()
      && $coverage['inspected'] === $this->counts['source']
      && $coverage['total_source'] === $this->counts['source'];
  }

  /**
   * Normalizes scope without turning an explicit sample into full evidence.
   *
   * @return array<string, mixed>
   *   Coverage metadata, including the inspected population size.
   */
  private function coverage(): array {
    $coverage = $this->coverage + [
      'scope' => 'full',
      'complete' => FALSE,
      'inspected' => $this->counts['sampled'] ?? 0,
      'total_source' => $this->counts['source'] ?? 0,
    ];
    if ($coverage['scope'] !== 'full') {
      $coverage['complete'] = FALSE;
    }
    return $coverage;
  }

  /**
   * Counts must describe the same fully inspected population.
   */
  private function countsMatch(): bool {
    foreach (['source', 'target', 'matched', 'sampled'] as $key) {
      if (!isset($this->counts[$key]) || !is_int($this->counts[$key]) || $this->counts[$key] < 0) {
        return FALSE;
      }
    }
    return $this->counts['source'] === $this->counts['target']
      && $this->counts['source'] === $this->counts['matched']
      && $this->counts['source'] === $this->counts['sampled'];
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
      'full_passed' => $this->fullPassed(),
      'coverage' => $this->coverage(),
      'counts' => $this->counts,
      'field_digests' => $this->field_digests,
      'exceptions' => $this->exceptions,
    ];
  }

}
