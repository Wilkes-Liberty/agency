<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Allowed input names for operating-run lifecycle actions.
 *
 * Replaces contrib tool InputDefinition maps. Unknown keys throw
 * without dumping caller payload. This is not ToolOperations.
 */
final class OperatingActions {

  /**
   * Returns the allowed input names for one lifecycle action.
   *
   * @param string $action
   *   Lifecycle action name.
   *
   * @return list<string>
   *   Allowed input names, in declaration order.
   *
   * @throws \InvalidArgumentException
   *   When the action is unknown.
   */
  public static function keys(string $action): array {
    return match ($action) {
      'claim' => [],
      'context' => ['run_id', 'lease'],
      'submit' => ['run_id', 'lease', 'draft'],
      'fail' => ['run_id', 'lease', 'failure_code'],
      default => throw new \InvalidArgumentException(
        'Unknown operating action.',
      ),
    };
  }

  /**
   * Adapts named inputs without widening fields or dumping payload.
   *
   * @param string $action
   *   Lifecycle action name.
   * @param array<string, mixed> $values
   *   Named inputs supplied for the action.
   *
   * @return array<string, mixed>
   *   The same values when every key is allowed.
   *
   * @throws \InvalidArgumentException
   *   When the action or an input name is unknown, or a failure
   *   code is not allowlisted.
   */
  public static function payload(string $action, array $values): array {
    $allowed = array_fill_keys(self::keys($action), TRUE);
    if (array_diff_key($values, $allowed)) {
      throw new \InvalidArgumentException(
        'Unknown operating action input.',
      );
    }
    if (array_diff_key($allowed, $values)) {
      throw new \InvalidArgumentException(
        'Unknown operating action input.',
      );
    }
    if ($action !== 'fail') {
      return $values;
    }
    $code = $values['failure_code'] ?? NULL;
    if (!is_string($code)
      || !in_array($code, OperatingRuns::FAILURE_CODES, TRUE)) {
      throw new \InvalidArgumentException(
        'Unknown operating run failure.',
      );
    }
    return $values;
  }

}
