<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Validates bounded recommendations against the run's disclosed evidence.
 */
final class OperatingDraft {

  /**
   * Serializes a validated draft; unknown fields and evidence fail closed.
   *
   * References must come from the server-generated context for this run.
   * Validation establishes structure and provenance, not truth or approval.
   *
   * @param array $draft
   *   Candidate recommendation payload for this run.
   * @param array $references
   *   Server-generated evidence identifiers disclosed to this run.
   *
   * @return string
   *   Canonical JSON receipt.
   *
   * @throws \InvalidArgumentException
   *   When fields, evidence, markup or size violate the contract.
   */
  public function encode(array $draft, array $references): string {
    $this->keys($draft, [
      'summary',
      'findings',
      'did',
      'skipped',
      'could_not_verify',
    ]);
    $normalized = ['summary' => $this->text($draft['summary'], 1200)];
    if (!is_array($draft['findings'])
      || !array_is_list($draft['findings'])
      || count($draft['findings']) > 20) {
      throw new \InvalidArgumentException('Use at most 20 findings.');
    }
    $normalized['findings'] = [];
    foreach ($draft['findings'] as $finding) {
      if (!is_array($finding)) {
        throw new \InvalidArgumentException('Each finding must be an object.');
      }
      $this->keys($finding, [
        'observation',
        'recommendation',
        'references',
      ]);
      if (!is_array($finding['references'])
        || !array_is_list($finding['references'])
        || !$finding['references']
        || count($finding['references']) > 10) {
        throw new \InvalidArgumentException(
          'Findings require bounded evidence references.',
        );
      }
      foreach ($finding['references'] as $reference) {
        if (!is_string($reference)
          || !in_array($reference, $references, TRUE)) {
          throw new \InvalidArgumentException(
            'A finding cites undisclosed evidence.',
          );
        }
      }
      $normalized['findings'][] = [
        'observation' => $this->text($finding['observation'], 1200),
        'recommendation' => $this->text($finding['recommendation'], 1200),
        'references' => array_values(array_unique($finding['references'])),
      ];
    }
    foreach (['did', 'skipped', 'could_not_verify'] as $field) {
      if (!is_array($draft[$field])
        || !array_is_list($draft[$field])
        || count($draft[$field]) > 10) {
        throw new \InvalidArgumentException('Invalid coverage declaration.');
      }
      $normalized[$field] = array_map(
        fn($text) => $this->text($text, 600),
        $draft[$field],
      );
    }
    if (!$normalized['did']) {
      throw new \InvalidArgumentException('State what the run reviewed.');
    }
    $json = json_encode(
      $normalized,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    if (strlen($json) > 32768) {
      throw new \InvalidArgumentException('Draft exceeds the storage limit.');
    }
    return $json;
  }

  /**
   * Requires the exact schema, regardless of JSON object property ordering.
   *
   * @param array $value
   *   Candidate object.
   * @param array $expected
   *   Required field names.
   *
   * @throws \InvalidArgumentException
   *   When the field set does not match the contract.
   */
  private function keys(array $value, array $expected): void {
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
      throw new \InvalidArgumentException(
        'Draft fields do not match the contract.',
      );
    }
  }

  /**
   * Accepts plain UTF-8 text, never markup or hidden control characters.
   *
   * @param mixed $value
   *   Candidate text.
   * @param int $limit
   *   Maximum byte length.
   *
   * @return string
   *   Trimmed plain UTF-8 text.
   *
   * @throws \InvalidArgumentException
   *   When the value is empty, oversized, marked up or not UTF-8.
   */
  private function text(mixed $value, int $limit): string {
    if (!is_string($value) || !preg_match('//u', $value)
      || trim($value) === '' || strlen($value) > $limit
      || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/', $value)) {
      throw new \InvalidArgumentException(
        'Draft text must be bounded plain UTF-8.',
      );
    }
    return trim($value);
  }

}
