<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\Workforce;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Workforce\AcceptStaffInvitation;
use Drupal\agency_staff\Workforce\AuthorityState;
use Drupal\agency_staff\Workforce\FieldMarking;
use Drupal\agency_staff\Workforce\HostAccountBinding;
use Drupal\agency_staff\Workforce\IdempotencyKey;
use Drupal\agency_staff\Workforce\InviteStaff;
use Drupal\agency_staff\Workforce\ProfileAudience;
use Drupal\agency_staff\Workforce\RevokeStaffInvitation;
use Drupal\agency_staff\Workforce\StaffClassification;
use Drupal\agency_staff\Workforce\UpdateProfileEvidence;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Failure cases for Slice 2 workforce input objects, recorded first.
 *
 * @group agency
 */
#[Group('agency')]
final class WorkforceInputValidationTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_staff\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_staff/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * InviteStaff refuses Discovery and client classifications.
   */
  public function testInviteStaffRejectsCrossDomainClassification(): void {
    foreach (['discovery', 'client', 'partner', 'supplier', 'explorer', 'consultant', ''] as $kind) {
      try {
        InviteStaff::fromArray([
          'display_name' => 'Example Person',
          'contact' => ['kind' => 'email', 'value' => 'person@example.test'],
          'classification' => $kind,
        ]);
        $this->fail('Classification ' . $kind . ' must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * InviteStaff refuses a blank or oversized display name.
   */
  public function testInviteStaffRejectsInvalidDisplayName(): void {
    foreach (['', " \n", str_repeat('a', 256), "nul\0name"] as $name) {
      try {
        InviteStaff::fromArray([
          'display_name' => $name,
          'contact' => ['kind' => 'email', 'value' => 'person@example.test'],
          'classification' => StaffClassification::EMPLOYEE,
        ]);
        $this->fail('Display name must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * InviteStaff refuses a contact that is missing, untyped or empty.
   */
  public function testInviteStaffRejectsInvalidContact(): void {
    $base = [
      'display_name' => 'Example Person',
      'classification' => StaffClassification::CONTRACTOR,
    ];
    foreach ([
      ['contact' => 'person@example.test'],
      ['contact' => ['kind' => 'Email', 'value' => 'person@example.test']],
      ['contact' => ['kind' => 'email', 'value' => '']],
      ['contact' => ['kind' => '', 'value' => 'person@example.test']],
    ] as $input) {
      try {
        InviteStaff::fromArray($base + $input);
        $this->fail('Contact must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * A synthetic employee invitation input is accepted.
   */
  public function testInviteStaffAcceptsSyntheticEmployee(): void {
    $input = InviteStaff::fromArray([
      'display_name' => 'Example Person',
      'contact' => ['kind' => 'email', 'value' => 'person@example.test'],
      'classification' => StaffClassification::EMPLOYEE,
    ]);
    $this->assertSame('Example Person', $input->displayName);
    $this->assertSame('email', $input->contact['kind']);
    $this->assertSame(StaffClassification::EMPLOYEE, $input->classification->id);
  }

  /**
   * Acceptance refuses GET and other non-POST methods.
   */
  public function testAcceptStaffInvitationRejectsNonPost(): void {
    $token = str_repeat('ab', 32);
    foreach (['GET', 'get', 'PUT', 'HEAD', '', NULL] as $method) {
      try {
        AcceptStaffInvitation::fromArray(['token' => $token, 'method' => $method]);
        $this->fail('Non-POST method must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('method_not_allowed', $exception->reason);
      }
    }
  }

  /**
   * Acceptance refuses a token that is not 64 lowercase hex characters.
   */
  public function testAcceptStaffInvitationRejectsMalformedToken(): void {
    foreach (['short', str_repeat('AB', 32), str_repeat('g', 64), str_repeat('ab', 31)] as $token) {
      try {
        AcceptStaffInvitation::fromArray(['token' => $token, 'method' => 'POST']);
        $this->fail('Malformed token must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * POST with a 64-hex token is accepted as input, not as a consumed grant.
   */
  public function testAcceptStaffInvitationAcceptsPostToken(): void {
    $token = str_repeat('ab', 32);
    $input = AcceptStaffInvitation::fromArray(['token' => $token, 'method' => 'post']);
    $this->assertSame($token, $input->token);
    $this->assertSame('POST', $input->method);
  }

  /**
   * Revocation refuses a non-UUID relationship identifier.
   */
  public function testRevokeStaffInvitationRejectsInvalidId(): void {
    foreach (['discovery-id', '', 12, 'not-a-uuid'] as $id) {
      try {
        RevokeStaffInvitation::fromArray(['relationship_id' => $id, 'version' => 1]);
        $this->fail('Relationship id must be a UUID.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * Revocation refuses a negative or non-integer version.
   */
  public function testRevokeStaffInvitationRejectsInvalidVersion(): void {
    $id = '00000000-0000-4000-8000-000000000001';
    foreach ([-1, '1', NULL, 1.5] as $version) {
      try {
        RevokeStaffInvitation::fromArray([
          'relationship_id' => $id,
          'version' => $version,
        ]);
        $this->fail('Version must be a non-negative integer.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * Profile evidence refuses an unknown audience.
   */
  public function testUpdateProfileEvidenceRejectsUnknownAudience(): void {
    try {
      UpdateProfileEvidence::fromArray([
        'person_uuid' => '00000000-0000-4000-8000-000000000002',
        'audience' => 'network',
        'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PUBLIC]],
      ]);
      $this->fail('Unknown audience must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Profile evidence refuses a field without a mapped marking.
   */
  public function testUpdateProfileEvidenceRejectsUnmarkedField(): void {
    try {
      UpdateProfileEvidence::fromArray([
        'person_uuid' => '00000000-0000-4000-8000-000000000002',
        'audience' => ProfileAudience::OWN,
        'fields' => [['name' => 'display_name']],
      ]);
      $this->fail('Unmapped marking must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Profile evidence refuses inline file bytes and remote bodies.
   */
  public function testUpdateProfileEvidenceRejectsInlineFiles(): void {
    $person = '00000000-0000-4000-8000-000000000002';
    foreach ([
      [['bytes' => 'not-a-file']],
      [['content' => 'payload']],
      ['data:text/plain,hello'],
      ['https://files.example.test/secret'],
    ] as $files) {
      try {
        UpdateProfileEvidence::fromArray([
          'person_uuid' => $person,
          'audience' => ProfileAudience::OWN,
          'fields' => [['name' => 'resume', 'marking' => FieldMarking::PERSONAL]],
          'files' => $files,
        ]);
        $this->fail('Inline file payload must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * Own-profile evidence with a File Gate UUID is accepted as input.
   */
  public function testUpdateProfileEvidenceAcceptsFileGateRef(): void {
    $file = '00000000-0000-4000-8000-0000000000aa';
    $input = UpdateProfileEvidence::fromArray([
      'person_uuid' => '00000000-0000-4000-8000-000000000002',
      'audience' => ProfileAudience::ACCOUNT_SUMMARY,
      'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PUBLIC]],
      'files' => [$file],
    ]);
    $this->assertCount(1, $input->files);
    $this->assertSame($file, $input->files[0]->uuid);
  }

  /**
   * Authority, keys and host bindings refuse malformed values.
   */
  public function testSupportingObjectsRefuseInvalidValues(): void {
    try {
      new AuthorityState('cutover');
      $this->fail('Unknown authority state must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
    try {
      new IdempotencyKey('short');
      $this->fail('Short idempotency key must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
    try {
      new HostAccountBinding('', '1');
      $this->fail('Empty host binding kind must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
    $state = new AuthorityState(AuthorityState::SHADOW);
    $this->assertTrue($state->agencyWrites());
    $this->assertFalse($state->agencyReads());
    $live = new AuthorityState(AuthorityState::AGENCY_AUTHORITATIVE);
    $this->assertTrue($live->agencyReads());
    $this->assertSame('invite-01', (new IdempotencyKey('invite-01'))->value);
  }

}
