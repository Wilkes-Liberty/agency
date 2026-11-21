<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_campaign\Kernel;

use Drupal\agency_campaign\Entity\Campaign;
use Drupal\agency_campaign\Exception\CampaignRefusal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deny paths, replay, timeout and required audit for launchCampaign.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CampaignActionTest extends CampaignKernelTestBase {

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
    'agency_staff',
    'agency_campaign',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (DomainStore::TYPES as $type) {
      $this->installEntitySchema($type);
    }
  }

  /**
   * Anonymous callers cannot launch a campaign.
   */
  public function testAnonymousIsForbidden(): void {
    $this->container->get('current_user')
      ->setAccount(new AnonymousUserSession());
    $this->assertRefusal('forbidden', [
      'title' => 'Synthetic campaign',
    ], 'launch-anon-key');
  }

  /**
   * An authenticated account without the explicit grant is refused.
   */
  public function testAuthenticatedWithoutPermissionIsForbidden(): void {
    $this->container->get('current_user')
      ->setAccount($this->authenticatedUser());
    $this->assertRefusal('forbidden', [
      'title' => 'Synthetic campaign',
    ], 'launch-auth-key');
  }

  /**
   * User 1 is refused without an explicit campaign grant.
   */
  public function testUserOneWithoutExplicitGrantIsForbidden(): void {
    $admin = User::load(1);
    $this->assertInstanceOf(User::class, $admin);
    $this->container->get('current_user')->setAccount($admin);
    $this->assertRefusal('forbidden', [
      'title' => 'Synthetic campaign',
    ], 'launch-admin-key');
  }

  /**
   * A caller-supplied tenant argument is never accepted.
   */
  public function testTenantArgumentIsForbidden(): void {
    $this->assertRefusal('forbidden', [
      'title' => 'Synthetic campaign',
      'tenant' => 'other',
    ], 'launch-tenant-key');
  }

  /**
   * A request deadline that has already passed is a timeout.
   */
  public function testExpiredNotAfterIsTimeout(): void {
    $this->assertRefusal('timeout', [
      'title' => 'Synthetic campaign',
      'not_after' => $this->container->get('datetime.time')
        ->getCurrentTime() - 1,
    ], 'launch-timeout-key');
    $this->assertSame(0, $this->campaignCount());
  }

  /**
   * An undersized idempotency key is refused.
   */
  public function testInvalidIdempotencyKeyIsRefused(): void {
    $this->assertRefusal('invalid', [
      'title' => 'Synthetic campaign',
    ], 'short');
  }

  /**
   * The same key with a different payload is refused.
   */
  public function testReplayWithDifferentPayloadIsRefused(): void {
    $launch = $this->container->get('agency_campaign.launch');
    $first = $launch->launch(['title' => 'First title'], 'launch-replay-key');
    $this->assertSame('committed', $first['outcome']);
    try {
      $launch->launch(['title' => 'Second title'], 'launch-replay-key');
      $this->fail('A drifted payload must be refused.');
    }
    catch (CampaignRefusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
    $this->assertSame(1, $this->campaignCount());
  }

  /**
   * The same key and payload returns the original receipt.
   */
  public function testReplayReturnsOriginalReceipt(): void {
    $launch = $this->container->get('agency_campaign.launch');
    $first = $launch->launch(['title' => 'Replay title'], 'launch-same-key');
    $second = $launch->launch(['title' => 'Replay title'], 'launch-same-key');
    $this->assertSame($first, $second);
    $this->assertSame(1, $this->campaignCount());
  }

  /**
   * A first launch stores a draft and required minimized evidence.
   */
  public function testLaunchCreatesDraftWithRequiredEvidence(): void {
    $result = $this->container->get('agency_campaign.launch')
      ->launch(['title' => 'Draft campaign'], 'launch-create-key');
    $this->assertSame('committed', $result['outcome']);
    $this->assertSame('draft', $result['receipt']['state']);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign');
    $loaded = $storage->loadByProperties([
      'uuid' => $result['receipt']['campaign_id'],
    ]);
    $campaign = reset($loaded);
    $this->assertInstanceOf(Campaign::class, $campaign);
    $this->assertSame('Draft campaign', $campaign->get('title')->value);
    $this->assertSame('draft', $campaign->get('status')->value);
    $row = $this->container->get('database')
      ->select('agency_campaign_outbox', 'o')
      ->fields('o')
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    $this->assertSame('launchCampaign', $row['operation']);
    $metadata = json_decode($row['metadata'], TRUE, 16, JSON_THROW_ON_ERROR);
    $this->assertSame($campaign->uuid(), $metadata['campaign_id']);
    $this->assertArrayNotHasKey('title', $metadata);
    $this->assertArrayNotHasKey('contact', $metadata);
    $this->assertArrayNotHasKey('token', $metadata);
  }

  /**
   * A second launch on an owned draft moves it to active.
   */
  public function testLaunchActivatesOwnedDraft(): void {
    $launch = $this->container->get('agency_campaign.launch');
    $created = $launch->launch(['title' => 'To activate'], 'launch-draft-key');
    $activated = $launch->launch([
      'campaign_uuid' => $created['receipt']['campaign_id'],
    ], 'launch-active-key');
    $this->assertSame('active', $activated['receipt']['state']);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign');
    $loaded = $storage->loadByProperties([
      'uuid' => $created['receipt']['campaign_id'],
    ]);
    $campaign = reset($loaded);
    $this->assertInstanceOf(Campaign::class, $campaign);
    $this->assertSame('active', $campaign->get('status')->value);
  }

  /**
   * Launch does not create Discovery records when that module is present.
   */
  public function testLaunchDoesNotCreateDiscoveryRecords(): void {
    $before = [];
    foreach (DomainStore::TYPES as $type) {
      $before[$type] = (int) $this->container->get('entity_type.manager')
        ->getStorage($type)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    $this->container->get('agency_campaign.launch')
      ->launch(['title' => 'Isolated campaign'], 'launch-isolated-key');
    foreach (DomainStore::TYPES as $type) {
      $after = (int) $this->container->get('entity_type.manager')
        ->getStorage($type)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $this->assertSame($before[$type], $after, $type);
    }
  }

  /**
   * Disabling campaign storage leaves Discovery installed.
   */
  public function testDisableLeavesStaffIntact(): void {
    $this->assertTrue(
      $this->container->get('module_installer')->uninstall(['agency_campaign']),
    );
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('agency_staff'),
    );
    $this->assertTrue(
      $this->container->get('database')->schema()
        ->tableExists('agency_discovery'),
    );
  }

  /**
   * An outbox failure rolls back the campaign write.
   */
  public function testEvidenceFailureRollsBackTheCampaign(): void {
    $schema = $this->container->get('database')->schema();
    $schema->renameTable(
      'agency_campaign_outbox',
      'agency_campaign_outbox_hidden',
    );
    try {
      $this->assertRefusal('failed', [
        'title' => 'Rolled back campaign',
      ], 'launch-outbox-key');
    }
    finally {
      $schema->renameTable(
        'agency_campaign_outbox_hidden',
        'agency_campaign_outbox',
      );
    }
    $this->assertSame(0, $this->campaignCount());
  }

  /**
   * Activating a missing or foreign campaign is not_found.
   */
  public function testUnknownCampaignIsNotFound(): void {
    $this->assertRefusal('not_found', [
      'campaign_uuid' => '00000000-0000-4000-8000-0000000000aa',
    ], 'launch-missing-key');
  }

  /**
   * A coordinator cannot activate another coordinator's draft.
   */
  public function testForeignCampaignIsNotFound(): void {
    $launch = $this->container->get('agency_campaign.launch');
    $created = $launch->launch(['title' => 'Owned campaign'], 'launch-owner-key');
    $other = User::create([
      'name' => 'other-campaign-coordinator',
      'status' => 1,
      'roles' => ['campaign_coordinator'],
    ]);
    $other->save();
    $this->container->get('current_user')->setAccount($other);
    $this->assertRefusal('not_found', [
      'campaign_uuid' => $created['receipt']['campaign_id'],
    ], 'launch-foreign-key');
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign');
    $loaded = $storage->loadByProperties([
      'uuid' => $created['receipt']['campaign_id'],
    ]);
    $campaign = reset($loaded);
    $this->assertInstanceOf(Campaign::class, $campaign);
    $this->assertSame('draft', $campaign->get('status')->value);
  }

  /**
   * Asserts launchCampaign refuses with an allowlisted reason.
   *
   * @param string $reason
   *   Expected refusal.
   * @param array<string, mixed> $input
   *   Command input.
   * @param string $key
   *   Idempotency key.
   */
  private function assertRefusal(
    string $reason,
    array $input,
    string $key,
  ): void {
    try {
      $this->container->get('agency_campaign.launch')->launch($input, $key);
      $this->fail('launchCampaign must refuse.');
    }
    catch (CampaignRefusal $exception) {
      $this->assertSame($reason, $exception->reason);
    }
  }

  /**
   * Counts stored campaigns without using entity access.
   */
  private function campaignCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
