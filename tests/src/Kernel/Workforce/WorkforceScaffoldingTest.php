<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Workforce;

use Drupal\agency_staff\InvitationCommands;
use Drupal\agency_staff\RelationshipCommands;
use Drupal\agency_staff\Workforce\AcceptStaffInvitation;
use Drupal\agency_staff\Workforce\AuthorityState;
use Drupal\agency_staff\Workforce\HostAccountBinding;
use Drupal\agency_staff\Workforce\InviteStaff;
use Drupal\agency_staff\Workforce\StaffClassification;
use Drupal\agency_staff\Workforce\WorkforceCommandsInterface;
use Drupal\agency_staff\Workforce\WorkforceHostAdapterInterface;
use Drupal\agency_staff\Workforce\WorkforceReadsInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves workforce scaffolding is loadable and unregistered.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class WorkforceScaffoldingTest extends KernelTestBase {

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
  ];

  /**
   * Workforce ports are not registered and Discovery services stay in place.
   */
  public function testWorkforceIsNotRegisteredAsServices(): void {
    $ids = $this->container->getServiceIds();
    foreach ([
      'agency_staff.workforce',
      'agency_staff.workforce_commands',
      'agency_staff.workforce_reads',
      'agency_staff.workforce_host',
    ] as $id) {
      $this->assertFalse(in_array($id, $ids, TRUE), $id . ' must not be registered.');
    }
    $this->assertTrue($this->container->has('agency_staff.commands'));
    $this->assertTrue($this->container->has('agency_staff.invitations'));
    $this->assertSame(RelationshipCommands::class, $this->container->getDefinition('agency_staff.commands')->getClass());
    $this->assertSame(InvitationCommands::class, $this->container->getDefinition('agency_staff.invitations')->getClass());
    $this->assertTrue(interface_exists(WorkforceCommandsInterface::class));
    $this->assertTrue(interface_exists(WorkforceReadsInterface::class));
    $this->assertTrue(interface_exists(WorkforceHostAdapterInterface::class));
  }

  /**
   * Value objects validate without a container service.
   */
  public function testValueObjectsInstantiateWithoutServices(): void {
    $invite = InviteStaff::fromArray([
      'display_name' => 'Example Contractor',
      'contact' => ['kind' => 'email', 'value' => 'contractor@example.test'],
      'classification' => StaffClassification::CONTRACTOR,
    ]);
    $this->assertSame(StaffClassification::CONTRACTOR, $invite->classification->id);
    $accept = AcceptStaffInvitation::fromArray([
      'token' => str_repeat('cd', 32),
      'method' => 'POST',
    ]);
    $this->assertSame('POST', $accept->method);
    $adapter = new class() implements WorkforceHostAdapterInterface {

      /**
       * Last captured invitation handed to host delivery.
       */
      public string $delivered = '';

      /**
       * {@inheritdoc}
       */
      public function authority(): AuthorityState {
        return new AuthorityState(AuthorityState::LEGACY_AUTHORITATIVE);
      }

      /**
       * {@inheritdoc}
       */
      public function provisionAcceptedStaff(StaffClassification $classification, string $personUuid): HostAccountBinding {
        return new HostAccountBinding('account', $personUuid);
      }

      /**
       * {@inheritdoc}
       */
      public function deliverCapturedInvitation(string $invitationUuid): void {
        $this->delivered = $invitationUuid;
      }

    };
    $this->assertSame(AuthorityState::LEGACY_AUTHORITATIVE, $adapter->authority()->id);
    $this->assertFalse($adapter->authority()->agencyWrites());
  }

}
