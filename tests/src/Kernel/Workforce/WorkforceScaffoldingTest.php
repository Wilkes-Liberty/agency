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
    foreach ($this->container->getDefinitions() as $id => $definition) {
      $class = $definition->getClass();
      if (is_string($class) && str_starts_with($class, 'Drupal\\agency_staff\\Workforce\\')) {
        $this->fail($id . ' must not register a workforce class.');
      }
    }
    $this->assertTrue($this->container->has('agency_staff.commands'));
    $this->assertTrue($this->container->has('agency_staff.invitations'));
    $this->assertSame(RelationshipCommands::class, $this->container->getDefinition('agency_staff.commands')->getClass());
    $this->assertSame(InvitationCommands::class, $this->container->getDefinition('agency_staff.invitations')->getClass());
    $types = array_keys($this->container->get('entity_type.manager')->getDefinitions());
    foreach ([
      'agency_person',
      'agency_principal',
      'agency_discovery',
      'agency_invitation',
      'agency_entitlement',
    ] as $type) {
      $this->assertContains($type, $types);
    }
    $this->assertNotContains('agency_workforce', $types);
    $commands = new \ReflectionClass(WorkforceCommandsInterface::class);
    $this->assertTrue($commands->hasMethod('inviteStaff'));
    $this->assertTrue($commands->hasMethod('acceptStaffInvitation'));
    $this->assertTrue($commands->hasMethod('revokeStaffInvitation'));
    $this->assertTrue($commands->hasMethod('updateProfileEvidence'));
    $reads = new \ReflectionClass(WorkforceReadsInterface::class);
    $this->assertTrue($reads->hasMethod('inspectInvitation'));
    $this->assertTrue($reads->hasMethod('ownProfile'));
    $this->assertTrue($reads->hasMethod('accountSummary'));
    $host = new \ReflectionClass(WorkforceHostAdapterInterface::class);
    $this->assertTrue($host->hasMethod('authority'));
    $return = $host->getMethod('authority')->getReturnType();
    $this->assertInstanceOf(\ReflectionNamedType::class, $return);
    $this->assertSame(AuthorityState::class, $return->getName());
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
    ]);
    $this->assertSame(str_repeat('cd', 32), $accept->token);
    $person = '00000000-0000-4000-8000-0000000000bb';
    $adapter = new class() implements WorkforceHostAdapterInterface {

      /**
       * Last captured invitation handed to host delivery.
       */
      public string $delivered = '';

      /**
       * {@inheritdoc}
       */
      public function authority(): AuthorityState {
        return new AuthorityState(AuthorityState::LEGACY);
      }

      /**
       * {@inheritdoc}
       */
      public function provisionAcceptedStaff(
        StaffClassification $classification,
        string $personUuid,
      ): HostAccountBinding {
        return new HostAccountBinding(HostAccountBinding::ACCOUNT, 'host-account-1001');
      }

      /**
       * {@inheritdoc}
       */
      public function deliverCapturedInvitation(string $invitationUuid): void {
        $this->delivered = $invitationUuid;
      }

    };
    $this->assertSame(AuthorityState::LEGACY, $adapter->authority()->id);
    $this->assertFalse($adapter->authority()->agencyWrites());
    $binding = $adapter->provisionAcceptedStaff($invite->classification, $person);
    $this->assertSame(HostAccountBinding::ACCOUNT, $binding->kind);
    $this->assertNotSame($person, $binding->value);
  }

}
