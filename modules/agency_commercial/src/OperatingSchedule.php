<?php

namespace Drupal\agency_commercial;

/**
 * Resolves civil-time schedules into stable, bounded UTC occurrences.
 */
final class OperatingSchedule {

  /**
   * Maximum catch-up interval.
   *
   * Older work requires an explicit skipped record.
   */
  public const MAX_WINDOW = 8 * 86400;

  /**
   * Returns occurrences in (after, through], keyed by routine and UTC instant.
   *
   * Iterating UTC avoids ambiguous or nonexistent local times during DST.
   * A repeated local hour has two distinct instants; a missing hour has none.
   * Callers persist keys under a unique constraint before launching any work.
   *
   * @param array $schedules
   *   Routine IDs mapped to seat, timezone, weekdays (ISO 1-7), hours,
   *   minutes.
   * @param int $after
   *   Exclusive lower bound, Unix seconds.
   * @param int $through
   *   Inclusive upper bound, Unix seconds.
   *
   * @return array
   *   Occurrences with routine, seat and scheduled_at, ordered by instant.
   *
   * @throws \InvalidArgumentException
   *   When the window, identity, interval or values are invalid.
   */
  public function occurrences(array $schedules, int $after, int $through): array {
    if ($after < 0 || $through < $after
      || $through - $after > self::MAX_WINDOW) {
      throw new \InvalidArgumentException('Invalid operating schedule window.');
    }
    if (count($schedules) > 32) {
      throw new \InvalidArgumentException('Too many operating schedules.');
    }
    $validated = [];
    foreach ($schedules as $id => $schedule) {
      if (!is_string($id)
        || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $id)
        || !is_array($schedule)
        || !is_string($schedule['seat'] ?? NULL)
        || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $schedule['seat'])
        || !is_string($schedule['timezone'] ?? NULL)
        || !in_array(
          $schedule['timezone'],
          \DateTimeZone::listIdentifiers(),
          TRUE,
        )) {
        throw new \InvalidArgumentException(
          'Invalid operating schedule identity.',
        );
      }
      foreach ([
        'weekdays' => [1, 7],
        'hours' => [0, 23],
        'minutes' => [0, 59],
      ] as $field => [$min, $max]) {
        $values = $schedule[$field] ?? NULL;
        if (!is_array($values) || !$values || !array_is_list($values)
          || count($values) > $max - $min + 1) {
          throw new \InvalidArgumentException(
            'Invalid operating schedule interval.',
          );
        }
        foreach ($values as $value) {
          if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(
              'Invalid operating schedule value.',
            );
          }
        }
      }
      $validated[$id] = [
        $schedule,
        new \DateTimeZone($schedule['timezone']),
      ];
    }
    $result = [];
    $instant = intdiv($after, 60) * 60 + 60;
    for (; $instant <= $through; $instant += 60) {
      foreach ($validated as $id => [$schedule, $timezone]) {
        $local = (new \DateTimeImmutable('@' . $instant))
          ->setTimezone($timezone);
        if (in_array((int) $local->format('N'), $schedule['weekdays'], TRUE)
          && in_array((int) $local->format('G'), $schedule['hours'], TRUE)
          && in_array((int) $local->format('i'), $schedule['minutes'], TRUE)) {
          $result[$id . ':' . $instant] = [
            'routine' => $id,
            'seat' => $schedule['seat'],
            'scheduled_at' => $instant,
          ];
        }
      }
    }
    return $result;
  }

}
