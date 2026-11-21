<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Entity\IntakeSubmission;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared kernel fixture for intake entity registration.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class IntakeKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'agency',
    'agency_intake',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_intake_submission');
  }

  /**
   * Builds a synthetic intake submission.
   */
  protected function submissionValues(array $overrides = []): array {
    return $overrides + [
      'organization_uuid' => '00000000-0000-4000-8000-0000000000b1',
      'status' => 'received',
      'payload' => '{"source":"example.test"}',
      'dispatch_key' => 'intake.dispatch.synthetic',
      'owner_uid' => 0,
    ];
  }

  /**
   * Creates a synthetic submission record without saving it.
   */
  protected function createSubmission(array $overrides = []): IntakeSubmission {
    $submission = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission')
      ->create($this->submissionValues($overrides));
    $this->assertInstanceOf(IntakeSubmission::class, $submission);
    return $submission;
  }

  /**
   * Creates an authenticated account that is not user 1.
   */
  protected function authenticatedUser(): User {
    $account = User::create([
      'name' => 'intake-reader',
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

}
