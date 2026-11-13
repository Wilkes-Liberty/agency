<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

use Drupal\agency_recruiting\Entity\Application;
use Drupal\agency_recruiting\Entity\CareerPosting;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared kernel fixture for recruiting entity registration.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class RecruitingKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'agency',
    'agency_recruiting',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_career_posting');
    $this->installEntitySchema('agency_application');
  }

  /**
   * Builds a synthetic posting that is not yet published.
   */
  protected function postingValues(array $overrides = []): array {
    return $overrides + [
      'title' => 'Synthetic posting',
      'hiring_status' => 'open',
      'body' => 'Plain source text, not rendered markup.',
      'eligibility' => '{"clearance":"none"}',
      'status' => 'draft',
      'owner_uid' => 0,
    ];
  }

  /**
   * Builds a synthetic application, optionally unbound from a posting.
   */
  protected function applicationValues(array $overrides = []): array {
    return $overrides + [
      'person_uuid' => '00000000-0000-4000-8000-0000000000cc',
      'status' => 'received',
      'payload' => '{"name":"Example Applicant"}',
      'resume_file_uuid' => '00000000-0000-4000-8000-0000000000dd',
      'geo' => '{"country":"US"}',
      'owner_uid' => 0,
    ];
  }

  /**
   * Creates a synthetic posting record without saving it.
   */
  protected function createPosting(array $overrides = []): CareerPosting {
    $posting = $this->container->get('entity_type.manager')
      ->getStorage('agency_career_posting')
      ->create($this->postingValues($overrides));
    $this->assertInstanceOf(CareerPosting::class, $posting);
    return $posting;
  }

  /**
   * Creates a synthetic application record without saving it.
   */
  protected function createApplication(array $overrides = []): Application {
    $application = $this->container->get('entity_type.manager')
      ->getStorage('agency_application')
      ->create($this->applicationValues($overrides));
    $this->assertInstanceOf(Application::class, $application);
    return $application;
  }

  /**
   * Creates an authenticated account that is not user 1.
   */
  protected function authenticatedUser(): User {
    $account = User::create([
      'name' => 'recruiting-reader',
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

}
