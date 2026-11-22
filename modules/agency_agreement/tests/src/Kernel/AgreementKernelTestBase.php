<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_agreement\Kernel;

use Drupal\agency_agreement\Entity\Agreement;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared kernel fixture for agreement storage.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class AgreementKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'agency',
    'agency_agreement',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_agreement');
    User::create(['name' => 'reserved', 'status' => 1])->save();
  }

  /**
   * Builds a synthetic agreement that is not yet signed.
   *
   * @param array<string, mixed> $overrides
   *   Field values.
   *
   * @return array<string, mixed>
   *   Create values.
   */
  protected function agreementValues(array $overrides = []): array {
    return $overrides + [
      'engagement_uuid' => '',
      'status' => 'draft',
      'terms' => 'Synthetic terms.',
      'owner_uid' => 0,
    ];
  }

  /**
   * Creates a synthetic agreement record without saving it.
   *
   * @param array<string, mixed> $overrides
   *   Field values.
   *
   * @return \Drupal\agency_agreement\Entity\Agreement
   *   Unsaved agreement.
   */
  protected function createAgreement(array $overrides = []): Agreement {
    $agreement = $this->container->get('entity_type.manager')
      ->getStorage('agency_agreement')
      ->create($this->agreementValues($overrides));
    $this->assertInstanceOf(Agreement::class, $agreement);
    return $agreement;
  }

  /**
   * Whether a service id is registered.
   *
   * PHPStan treats ContainerInterface::has() as false for unknown ids.
   */
  protected function hasService(string $id): bool {
    return in_array($id, $this->container->getServiceIds(), TRUE);
  }

  /**
   * Creates an authenticated account that is not user 1.
   */
  protected function authenticatedUser(): User {
    $account = User::create([
      'name' => 'agreement-reader',
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

}
