<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Publication refuses missing or past close dates; a future date is accepted.
 *
 * Failure cases are recorded first: published with no close date, and
 * published with a past close date.
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
