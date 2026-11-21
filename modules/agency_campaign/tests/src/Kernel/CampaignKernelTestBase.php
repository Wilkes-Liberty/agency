<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_campaign\Kernel;

use Drupal\agency_campaign\Entity\Campaign;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared kernel fixture for campaign storage and launch.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class CampaignKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'key',
    'encrypt',
    'audit_chain',
    'field_guard',
    'agency',
    'agency_campaign',
  ];

  /**
   * Explicitly authorized coordinator, distinct from uid 1.
   */
  protected User $coordinator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_campaign');
    $this->installSchema('agency_campaign', [
      'agency_campaign_outbox',
      'agency_campaign_intent',
    ]);
    $this->installConfig(['system', 'user', 'audit_chain']);
    User::create(['name' => 'reserved', 'status' => 1])->save();
    Role::create([
      'id' => 'campaign_coordinator',
      'label' => 'Campaign coordinator',
      'permissions' => ['coordinate agency campaigns'],
    ])->save();
    $this->coordinator = User::create([
      'name' => 'example-campaign-coordinator',
      'status' => 1,
      'roles' => ['campaign_coordinator'],
    ]);
    $this->coordinator->save();
    $this->container->get('current_user')->setAccount($this->coordinator);
  }

  /**
   * Builds a synthetic campaign that is not yet launched.
   *
   * @param array<string, mixed> $overrides
   *   Field values.
   *
   * @return array<string, mixed>
   *   Create values.
   */
  protected function campaignValues(array $overrides = []): array {
    return $overrides + [
      'title' => 'Synthetic campaign',
      'status' => 'draft',
      'owner_uid' => (int) $this->coordinator->id(),
    ];
  }

  /**
   * Creates a synthetic campaign record without saving it.
   *
   * @param array<string, mixed> $overrides
   *   Field values.
   *
   * @return \Drupal\agency_campaign\Entity\Campaign
   *   Unsaved campaign.
   */
  protected function createCampaign(array $overrides = []): Campaign {
    $campaign = $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign')
      ->create($this->campaignValues($overrides));
    $this->assertInstanceOf(Campaign::class, $campaign);
    return $campaign;
  }

  /**
   * Creates an authenticated account that is not user 1.
   */
  protected function authenticatedUser(): User {
    $account = User::create([
      'name' => 'campaign-reader',
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

}
