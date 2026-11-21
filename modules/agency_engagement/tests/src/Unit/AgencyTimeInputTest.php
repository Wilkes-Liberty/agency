<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\TimeInput;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exact seconds and explicit calendar provenance, without tracker fields.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyTimeInputTest extends TestCase {

  /**
   * Duration does not fabricate start/end; DST and month edges use UTC elapsed.
   */
  public function testExactTimeProvenance(): void {
    $duration = TimeInput::normalize([
      'mode' => 'duration',
      'date' => '2026-09-30',
      'timezone' => 'UTC',
      'seconds' => 3727,
    ]);
    $this->assertSame(3727, $duration['seconds']);
    $this->assertNull($duration['started_at']);
    $this->assertNull($duration['ended_at']);
    $spring = TimeInput::normalize([
      'mode' => 'interval',
      'date' => '2026-03-08',
      'timezone' => 'America/New_York',
      'start' => '2026-03-08T06:30:00Z',
      'end' => '2026-03-08T07:30:00Z',
    ]);
    $this->assertSame(3600, $spring['seconds']);
    $fall = TimeInput::normalize([
      'mode' => 'interval',
      'date' => '2026-11-01',
      'timezone' => 'America/New_York',
      'start' => '2026-11-01T05:30:00Z',
      'end' => '2026-11-01T06:30:00Z',
    ]);
    $this->assertSame(3600, $fall['seconds']);
    $month = TimeInput::normalize([
      'mode' => 'interval',
      'date' => '2026-09-30',
      'timezone' => 'UTC',
      'start' => '2026-09-30T23:59:53Z',
      'end' => '2026-10-01T00:00:07Z',
    ]);
    $this->assertSame(14, $month['seconds']);
  }

  /**
   * Ambiguous, disagreeing and invalid input cannot be silently normalized.
   */
  public function testInvalidInputs(): void {
    $base = [
      'mode' => 'duration',
      'date' => '2026-09-30',
      'timezone' => 'UTC',
      'seconds' => 3727,
    ];
    $cases = [
      ['seconds' => 0],
      ['seconds' => '3600'],
      ['seconds' => 1.5],
      ['seconds' => 604801],
      ['date' => '2026-02-30'],
      ['timezone' => 'Mars/Olympus'],
      ['start' => '2026-09-30T00:00:00Z'],
      ['note' => str_repeat('a', 2001)],
      ['actor' => 12],
      [
        'mode' => 'interval',
        'start' => '2026-09-30T00:00:00Z',
        'end' => '2026-09-30T01:00:00Z',
      ],
    ];
    foreach ($cases as $change) {
      $caught = FALSE;
      try {
        TimeInput::normalize(array_replace($base, $change));
      }
      catch (\InvalidArgumentException) {
        $caught = TRUE;
      }
      $this->assertTrue($caught, 'Invalid input was accepted.');
    }
  }

  /**
   * Tracker context is unsupported and is not copied onto the result.
   */
  public function testTrackerContextIsUnsupported(): void {
    $this->expectException(\InvalidArgumentException::class);
    TimeInput::normalize([
      'mode' => 'duration',
      'date' => '2026-09-30',
      'timezone' => 'UTC',
      'seconds' => 3727,
      'task_ref' => 'PROJ-1',
    ]);
  }

}
