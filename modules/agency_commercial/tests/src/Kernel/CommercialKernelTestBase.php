<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Entity\Activity;
use Drupal\agency_commercial\Entity\Opportunity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared kernel fixture for commercial entity registration.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class CommercialKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'agency',
    'agency_commercial',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_opportunity');
    $this->installEntitySchema('agency_activity');
  }

  /**
   * Builds a synthetic opportunity without inventing an amount.
   */
  protected function opportunityValues(array $overrides = []): array {
    return $overrides + [
      'organization_uuid' => '00000000-0000-4000-8000-0000000000a1',
      'title' => 'Synthetic opportunity',
      'stage' => 'qualify',
      'status' => 'open',
      'owner_uid' => 0,
    ];
  }

  /**
   * Builds a synthetic activity against an opportunity UUID.
   */
  protected function activityValues(array $overrides = []): array {
    return $overrides + [
      'opportunity_uuid' => '00000000-0000-4000-8000-0000000000a1',
      'kind' => 'note',
      'summary' => 'Synthetic activity summary.',
      'occurred_at' => 1700000000,
      'owner_uid' => 0,
    ];
  }

  /**
   * Creates a synthetic opportunity record without saving it.
   */
  protected function createOpportunity(array $overrides = []): Opportunity {
    $opportunity = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity')
      ->create($this->opportunityValues($overrides));
    $this->assertInstanceOf(Opportunity::class, $opportunity);
    return $opportunity;
  }

  /**
   * Creates a synthetic activity record without saving it.
   */
  protected function createActivity(array $overrides = []): Activity {
    $activity = $this->container->get('entity_type.manager')
      ->getStorage('agency_activity')
      ->create($this->activityValues($overrides));
    $this->assertInstanceOf(Activity::class, $activity);
    return $activity;
  }

  /**
   * Creates an authenticated account that is not user 1.
   */
  protected function authenticatedUser(): User {
    $account = User::create([
      'name' => 'commercial-reader',
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

}
