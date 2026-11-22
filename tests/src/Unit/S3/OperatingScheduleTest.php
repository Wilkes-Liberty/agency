<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once dirname(__DIR__, 4)
  . '/modules/agency_commercial/src/OperatingSchedule.php';

use Drupal\agency_commercial\OperatingSchedule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises civil time boundaries independently of Drupal's container.
 *
 * @group agency
 */
#[Group('agency')]
final class OperatingScheduleTest extends TestCase {

  /**
   * Builds a civil-time schedule fixture.
   *
   * @param array $hours
   *   Hours in 0-23. Defaults to 07:xx.
   * @param array $minutes
   *   Minutes in 0-59. Defaults to xx:30.
   *
   * @return array
   *   One routine mapped to seat, timezone, weekdays, hours and minutes.
   */
  private function schedule(array $hours = [7], array $minutes = [30]): array {
    return [
      'review' => [
        'seat' => 'steward',
        'timezone' => 'America/New_York',
        'weekdays' => range(1, 7),
        'hours' => $hours,
        'minutes' => $minutes,
      ],
    ];
  }

  /**
   * Daily review keeps civil time across spring change.
   */
  public function testDailyReviewKeepsCivilTimeAcrossSpringChange(): void {
    $runs = (new OperatingSchedule())->occurrences(
      $this->schedule(),
      strtotime('2026-03-07T00:00:00Z'),
      strtotime('2026-03-10T00:00:00Z'),
    );
    $this->assertSame([
      strtotime('2026-03-07T12:30:00Z'),
      strtotime('2026-03-08T11:30:00Z'),
      strtotime('2026-03-09T11:30:00Z'),
    ], array_column($runs, 'scheduled_at'));
  }

  /**
   * Repeated hour has distinct stable keys.
   */
  public function testRepeatedHourHasDistinctStableKeys(): void {
    $runs = (new OperatingSchedule())->occurrences(
      $this->schedule([1], [0]),
      strtotime('2026-11-01T00:00:00Z'),
      strtotime('2026-11-02T00:00:00Z'),
    );
    $this->assertSame([
      strtotime('2026-11-01T05:00:00Z'),
      strtotime('2026-11-01T06:00:00Z'),
    ], array_column($runs, 'scheduled_at'));
    $this->assertCount(2, array_unique(array_keys($runs)));
  }

  /**
   * Missing hour does not invent an occurrence.
   */
  public function testMissingHourDoesNotInventAnOccurrence(): void {
    $this->assertSame([], (new OperatingSchedule())->occurrences(
      $this->schedule([2], [30]),
      strtotime('2026-03-08T00:00:00Z'),
      strtotime('2026-03-09T00:00:00Z'),
    ));
  }

  /**
   * Adjacent polls do not duplicate boundary.
   */
  public function testAdjacentPollsDoNotDuplicateBoundary(): void {
    $clock = new OperatingSchedule();
    $due = strtotime('2026-09-17T11:30:00Z');
    $this->assertCount(
      1,
      $clock->occurrences($this->schedule(), $due - 61, $due),
    );
    $this->assertSame(
      [],
      $clock->occurrences($this->schedule(), $due, $due + 60),
    );
  }

  /**
   * Weekday hourly intake excludes weekend.
   */
  public function testWeekdayHourlyIntakeExcludesWeekend(): void {
    $schedule = $this->schedule(range(0, 23), [0]);
    $schedule['review']['weekdays'] = range(1, 5);
    $runs = (new OperatingSchedule())->occurrences(
      $schedule,
      strtotime('2026-09-18T03:59:59Z'),
      strtotime('2026-09-21T03:59:59Z'),
    );
    $this->assertCount(24, $runs);
  }

  /**
   * Unbounded catchup is refused.
   */
  public function testUnboundedCatchupIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingSchedule())->occurrences(
      $this->schedule(),
      0,
      OperatingSchedule::MAX_WINDOW + 1,
    );
  }

  /**
   * Malformed configuration is refused.
   */
  public function testMalformedConfigurationIsRefused(): void {
    $schedule = $this->schedule();
    $schedule['review']['hours'] = ['7'];
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingSchedule())->occurrences($schedule, 0, 60);
  }

}
