<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks the close deadline for published openings.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class PublishedCloseDateConstraintTest extends RecruitingKernelTestBase {

  /**
   * Publishing an open posting with no close date is refused.
   */
  public function testPublishedWithoutCloseDateIsRefused(): void {
    $posting = $this->createPosting([
      'status' => 'published',
      'hiring_status' => 'open',
    ]);
    $violations = $posting->validate();
    $this->assertCount(1, $violations);
    $this->assertSame(
      'A published posting requires a close date.',
      (string) $violations[0]->getMessage(),
    );
  }

  /**
   * Publishing an open posting with a past close date is refused.
   */
  public function testPublishedWithPastCloseDateIsRefused(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $posting = $this->createPosting([
      'status' => 'published',
      'hiring_status' => 'open',
      'valid_through' => $now - 1,
    ]);
    $violations = $posting->validate();
    $this->assertCount(1, $violations);
    $this->assertSame(
      'A published posting cannot have a past close date.',
      (string) $violations[0]->getMessage(),
    );
  }

  /**
   * A published posting with a future close date validates and stores.
   */
  public function testPublishedWithFutureCloseDateIsAccepted(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $posting = $this->createPosting([
      'status' => 'published',
      'hiring_status' => 'open',
      'valid_through' => $now + 86400,
    ]);
    $this->assertCount(0, $posting->validate());
    $posting->save();
    $this->assertGreaterThan(0, (int) $posting->id());
    $this->assertSame('published', (string) $posting->get('status')->value);
    $this->assertSame($now + 86400, (int) $posting->get('valid_through')->value);
  }

  /**
   * A published posting can stay visible after it stops accepting applicants.
   *
   * @dataProvider nonOpenPostings
   */
  #[DataProvider('nonOpenPostings')]
  public function testPublishedNonOpenPostingsMayBeMissingOrPastCloseDate(string $hiring_status, bool $has_close_date): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $posting = $this->createPosting([
      'status' => 'published',
      'hiring_status' => $hiring_status,
      'valid_through' => $has_close_date ? $now - 1 : NULL,
    ]);
    $this->assertCount(0, $posting->validate());
  }

  /**
   * Supplies non-open postings without an upcoming deadline.
   */
  public static function nonOpenPostings(): iterable {
    yield 'paused without a deadline' => ['paused', FALSE];
    yield 'filled with an expired deadline' => ['filled', TRUE];
  }

  /**
   * Accepts requests before, and refuses requests at, the supplied deadline.
   *
   * @dataProvider localDayDeadlines
   */
  #[DataProvider('localDayDeadlines')]
  public function testExclusiveDeadlineAtDaylightSavingOffsets(string $end): void {
    $deadline = (new \DateTimeImmutable($end))->getTimestamp();
    $posting = $this->createPosting([
      'status' => 'published',
      'hiring_status' => 'open',
      'valid_through' => $deadline,
    ]);
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturnOnConsecutiveCalls($deadline - 1, $deadline);
    $this->container->set('datetime.time', $time);

    $this->assertCount(0, $posting->validate());
    $violations = $posting->validate();
    $this->assertCount(1, $violations);
    $this->assertSame(
      'A published posting cannot have a past close date.',
      (string) $violations[0]->getMessage(),
    );
  }

  /**
   * Supplies the site's next local midnight as an absolute instant.
   */
  public static function localDayDeadlines(): iterable {
    // March 8 and November 1 in America/New_York are 23 and 25 hours long.
    yield 'spring forward' => ['2026-03-09T04:00:00Z'];
    yield 'fall back' => ['2026-11-02T05:00:00Z'];
  }

  /**
   * A draft posting may omit a close date.
   */
  public function testDraftWithoutCloseDateIsAccepted(): void {
    $posting = $this->createPosting();
    $this->assertCount(0, $posting->validate());
    $posting->save();
    $this->assertSame('draft', (string) $posting->get('status')->value);
    $this->assertTrue($posting->get('valid_through')->isEmpty());
  }

  /**
   * A general application with no posting reference is accepted.
   */
  public function testGeneralApplicationWithoutPostingIsAccepted(): void {
    $application = $this->createApplication();
    $this->assertTrue($application->get('posting_uuid')->isEmpty());
    $this->assertCount(0, $application->validate());
    $application->save();
    $this->assertGreaterThan(0, (int) $application->id());
    $this->assertSame('', (string) $application->get('posting_uuid')->value);
    $this->assertSame('received', (string) $application->get('status')->value);
    $this->assertSame(
      '00000000-0000-4000-8000-0000000000dd',
      (string) $application->get('resume_file_uuid')->value,
    );
  }

}
