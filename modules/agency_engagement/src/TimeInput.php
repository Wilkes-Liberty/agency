<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Normalizes duration or interval input without tracker identifiers.
 *
 * Storage-free. Does not invent intervals or apply billing policy.
 * Host adapters may still accept tracker context; this class refuses
 * unknown keys rather than copy them.
 */
final class TimeInput {

  /**
   * Keys this normalizer accepts.
   */
  private const KEYS = [
    'mode',
    'date',
    'timezone',
    'seconds',
    'start',
    'end',
    'note',
    'public_summary',
    'task_label',
    'billable',
  ];

  /**
   * Validates a bounded command, retaining exact UTC elapsed seconds.
   *
   * @param array<string, mixed> $input
   *   Caller-supplied duration or interval fields.
   *
   * @return array<string, mixed>
   *   Normalized provenance. Tracker identifiers are never present.
   *
   * @throws \InvalidArgumentException
   *   When the input is unsupported, disagreeing or out of bounds.
   */
  public static function normalize(array $input): array {
    if (array_diff(array_keys($input), self::KEYS)) {
      throw new \InvalidArgumentException('Unsupported time input.');
    }
    $mode = $input['mode'] ?? NULL;
    $zone = $input['timezone'] ?? NULL;
    $date = $input['date'] ?? NULL;
    $note = $input['note'] ?? '';
    $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
    $date_ok = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date);
    $note_ok = is_string($note) && mb_strlen($note) <= 2000;
    if (!is_string($zone) || !in_array($zone, $zones, TRUE)
      || !$date_ok || !$note_ok) {
      throw new \InvalidArgumentException(
        'Use a valid date, IANA timezone and bounded private note.',
      );
    }
    $day = \DateTimeImmutable::createFromFormat(
      '!Y-m-d',
      $date,
      new \DateTimeZone($zone),
    );
    if (!$day || $day->format('Y-m-d') !== $date) {
      throw new \InvalidArgumentException('Invalid work date.');
    }
    $start = $end = NULL;
    if ($mode === 'duration') {
      if (array_key_exists('start', $input)
        || array_key_exists('end', $input)) {
        throw new \InvalidArgumentException(
          'Duration input cannot claim an interval.',
        );
      }
      $seconds = $input['seconds'] ?? NULL;
    }
    elseif ($mode === 'interval') {
      $start = self::instant($input['start'] ?? NULL);
      $end = self::instant($input['end'] ?? NULL);
      $seconds = $end->getTimestamp() - $start->getTimestamp();
      if (array_key_exists('seconds', $input)
        && $input['seconds'] !== $seconds) {
        throw new \InvalidArgumentException(
          'Duration disagrees with UTC interval.',
        );
      }
      $local_start = $start->setTimezone(new \DateTimeZone($zone));
      if ($local_start->format('Y-m-d') !== $date) {
        throw new \InvalidArgumentException(
          'Work date must match the interval start in its display timezone.',
        );
      }
    }
    else {
      throw new \InvalidArgumentException(
        'Choose duration or interval input.',
      );
    }
    if (!is_int($seconds) || $seconds < 1 || $seconds > 604800) {
      throw new \InvalidArgumentException(
        'Time must be 1–604800 integer seconds.',
      );
    }
    $context = [];
    $context_limits = [
      'public_summary' => 2000,
      'task_label' => 255,
    ];
    foreach ($context_limits as $name => $maximum) {
      if (!array_key_exists($name, $input)) {
        continue;
      }
      $value = $input[$name];
      $control = '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u';
      if (!is_string($value) || mb_strlen($value) > $maximum
        || preg_match($control, $value)) {
        throw new \InvalidArgumentException(
          'Use bounded plain text context.',
        );
      }
      $context[$name] = trim($value);
    }
    if (array_key_exists('billable', $input)) {
      if (!is_bool($input['billable'])) {
        throw new \InvalidArgumentException(
          'Billable must be an explicit boolean.',
        );
      }
      $context['billable'] = $input['billable'];
    }
    return $context + [
      'input_mode' => $mode,
      'entry_date' => $date,
      'timezone' => $zone,
      'seconds' => $seconds,
      'started_at' => $start?->format('Y-m-d\TH:i:s\Z'),
      'ended_at' => $end?->format('Y-m-d\TH:i:s\Z'),
      'note' => $note,
    ];
  }

  /**
   * Strict UTC seconds eliminate ambiguous local wall-clock times.
   */
  private static function instant(mixed $value): \DateTimeImmutable {
    if (!is_string($value)) {
      throw new \InvalidArgumentException('UTC start and end are required.');
    }
    $instant = \DateTimeImmutable::createFromFormat(
      '!Y-m-d\TH:i:s\Z',
      $value,
      new \DateTimeZone('UTC'),
    );
    if (!$instant || $instant->format('Y-m-d\TH:i:s\Z') !== $value) {
      throw new \InvalidArgumentException(
        'Use exact UTC instants ending in Z.',
      );
    }
    return $instant;
  }

}
