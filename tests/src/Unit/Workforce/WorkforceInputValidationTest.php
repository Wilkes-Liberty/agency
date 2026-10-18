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
use Drupal\agency_staff\Workforce\ProtectedFileRef;
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
   * Direct construction also refuses an empty display name.
   */
  public function testInviteStaffConstructorRejectsEmptyDisplayName(): void {
    try {
      new InviteStaff(
        '',
        ['kind' => 'email', 'value' => 'person@example.test'],
        new StaffClassification(StaffClassification::EMPLOYEE),
      );
      $this->fail('Constructor must refuse an empty display name.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * InviteStaff refuses a contact that is missing, untyped, empty or extra.
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
      ['contact' => ['kind' => 'email', 'value' => 'person@example.test', 'principal' => 'sub']],
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
   * InviteStaff refuses unexpected top-level keys.
   */
  public function testInviteStaffRejectsExtraKeys(): void {
    try {
      InviteStaff::fromArray([
        'display_name' => 'Example Person',
        'contact' => ['kind' => 'email', 'value' => 'person@example.test'],
        'classification' => StaffClassification::EMPLOYEE,
        'uid' => 12,
      ]);
      $this->fail('Extra invite keys must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
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
   * Acceptance input refuses a request method field; POST is command-layer.
   */
  public function testAcceptStaffInvitationRejectsMethodField(): void {
    try {
      AcceptStaffInvitation::fromArray([
        'token' => str_repeat('ab', 32),
        'method' => 'POST',
      ]);
      $this->fail('Request method must not be an accept input field.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Acceptance refuses a token that is not 64 lowercase hex characters.
   */
  public function testAcceptStaffInvitationRejectsMalformedToken(): void {
    $tokens = [
      'short',
      str_repeat('AB', 32),
      str_repeat('g', 64),
      str_repeat('ab', 31),
      '',
    ];
    foreach ($tokens as $token) {
      try {
        AcceptStaffInvitation::fromArray(['token' => $token]);
        $this->fail('Malformed token must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * A 64-hex token is accepted as input, not as a consumed grant.
   */
  public function testAcceptStaffInvitationAcceptsToken(): void {
    $token = str_repeat('ab', 32);
    $input = AcceptStaffInvitation::fromArray(['token' => $token]);
    $this->assertSame($token, $input->token);
  }

  /**
   * Direct construction also refuses a malformed token.
   */
  public function testAcceptStaffInvitationConstructorRejectsMalformedToken(): void {
    try {
      new AcceptStaffInvitation('short');
      $this->fail('Constructor must refuse a malformed token.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
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
   * Profile evidence refuses an unknown or read-only audience on writes.
   */
  public function testUpdateProfileEvidenceRejectsUnknownAudience(): void {
    foreach (['network', ProfileAudience::ACCOUNT_SUMMARY] as $audience) {
      try {
        UpdateProfileEvidence::fromArray([
          'person_uuid' => '00000000-0000-4000-8000-000000000002',
          'audience' => $audience,
          'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PERSONAL]],
        ]);
        $this->fail('Write audience ' . $audience . ' must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
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
   * Callers cannot choose a weaker marking than the server map.
   */
  public function testUpdateProfileEvidenceRejectsCallerChosenMarking(): void {
    try {
      UpdateProfileEvidence::fromArray([
        'person_uuid' => '00000000-0000-4000-8000-000000000002',
        'audience' => ProfileAudience::OWN,
        'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PUBLIC]],
      ]);
      $this->fail('Caller-chosen public marking must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Unmapped field names refuse even with an allowed marking id.
   */
  public function testUpdateProfileEvidenceRejectsUnmappedFieldName(): void {
    try {
      UpdateProfileEvidence::fromArray([
        'person_uuid' => '00000000-0000-4000-8000-000000000002',
        'audience' => ProfileAudience::OWN,
        'fields' => [['name' => 'salary', 'marking' => FieldMarking::FINANCIAL]],
      ]);
      $this->fail('Unmapped field name must be refused.');
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
    $uuid = '00000000-0000-4000-8000-0000000000aa';
    foreach ([
      [['bytes' => 'not-a-file']],
      [['content' => 'payload']],
      [['bytes' => NULL, 'uuid' => $uuid]],
      [['uuid' => $uuid, 'uri' => NULL]],
      [['uuid' => $uuid, 'body' => 'x']],
      ['data:text/plain,hello'],
      ['https://files.example.test/secret'],
    ] as $files) {
      try {
        UpdateProfileEvidence::fromArray([
          'person_uuid' => $person,
          'audience' => ProfileAudience::OWN,
          'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PERSONAL]],
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
   * Field objects refuse extra payload keys.
   */
  public function testUpdateProfileEvidenceRejectsExtraFieldKeys(): void {
    try {
      UpdateProfileEvidence::fromArray([
        'person_uuid' => '00000000-0000-4000-8000-000000000002',
        'audience' => ProfileAudience::OWN,
        'fields' => [
          [
            'name' => 'display_name',
            'marking' => FieldMarking::PERSONAL,
            'bytes' => NULL,
          ],
        ],
      ]);
      $this->fail('Extra field keys must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Own-profile evidence with a File Gate UUID is accepted as input.
   */
  public function testUpdateProfileEvidenceAcceptsFileGateRef(): void {
    $file = '00000000-0000-4000-8000-0000000000aa';
    $input = UpdateProfileEvidence::fromArray([
      'person_uuid' => '00000000-0000-4000-8000-000000000002',
      'audience' => ProfileAudience::OWN,
      'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PERSONAL]],
      'files' => [$file],
    ]);
    $this->assertCount(1, $input->files);
    $this->assertSame($file, $input->files[0]->uuid);
    $arrayRef = UpdateProfileEvidence::fromArray([
      'person_uuid' => '00000000-0000-4000-8000-000000000002',
      'audience' => ProfileAudience::OWN,
      'fields' => [['name' => 'display_name', 'marking' => FieldMarking::PERSONAL]],
      'files' => [['uuid' => $file]],
    ]);
    $this->assertSame($file, $arrayRef->files[0]->uuid);
  }

  /**
   * A File Gate array missing uuid refuses even when empty of bodies.
   */
  public function testProtectedFileRefRejectsArrayWithoutUuid(): void {
    try {
      ProtectedFileRef::fromUnknown([]);
      $this->fail('File ref without uuid must be refused.');
    }
    catch (Refusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Authority ids match the migrate register; forked names refuse.
   */
  public function testAuthorityStateMatchesMigrateVocabulary(): void {
    $forked = [
      'legacy_authoritative',
      'agency_authoritative',
      'legacy_removed',
      'cutover',
    ];
    foreach ($forked as $id) {
      try {
        new AuthorityState($id);
        $this->fail('Forked authority id ' . $id . ' must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
    $legacy = new AuthorityState(AuthorityState::LEGACY);
    $this->assertFalse($legacy->agencyWrites());
    $this->assertFalse($legacy->agencyReads());
    $this->assertTrue($legacy->legacyWrites());
    $this->assertTrue($legacy->legacyReads());
    $shadow = new AuthorityState(AuthorityState::SHADOW);
    $this->assertTrue($shadow->agencyWrites());
    $this->assertFalse($shadow->agencyReads());
    $this->assertTrue($shadow->legacyWrites());
    $this->assertTrue($shadow->legacyReads());
    $agency = new AuthorityState(AuthorityState::AGENCY);
    $this->assertTrue($agency->agencyWrites());
    $this->assertTrue($agency->agencyReads());
    $this->assertFalse($agency->legacyWrites());
    $this->assertTrue($agency->legacyReads());
    $removed = new AuthorityState(AuthorityState::REMOVED);
    $this->assertTrue($removed->agencyWrites());
    $this->assertTrue($removed->agencyReads());
    $this->assertFalse($removed->legacyWrites());
    $this->assertFalse($removed->legacyReads());
  }

  /**
   * Authority, keys and host bindings refuse malformed values.
   */
  public function testSupportingObjectsRefuseInvalidValues(): void {
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
    foreach (['discovery', 'person', 'workforce'] as $kind) {
      try {
        new HostAccountBinding($kind, '1001');
        $this->fail('Host binding kind ' . $kind . ' must be refused.');
      }
      catch (Refusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
    $binding = new HostAccountBinding(HostAccountBinding::ACCOUNT, '1001');
    $this->assertSame('account', $binding->kind);
    $this->assertSame('1001', $binding->value);
    $this->assertSame('invite-01', (new IdempotencyKey('invite-01'))->value);
  }

}
