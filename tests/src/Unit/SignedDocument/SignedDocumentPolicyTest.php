<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\SignedDocument;

use Drupal\agency_workforce\PersonnelFile\EvidenceFileItem;
use Drupal\agency_workforce\PersonnelFile\QualifiedFile;
use Drupal\agency_workforce\SignedDocument\AttachmentSnapshot;
use Drupal\agency_workforce\SignedDocument\HistorySnapshot;
use Drupal\agency_workforce\SignedDocument\MappingSnapshot;
use Drupal\agency_workforce\SignedDocument\SignedDocumentPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Adversarial qualification across source claims, ordered pins and retries.
 *
 * @group agency
 */
#[Group('agency')]
final class SignedDocumentPolicyTest extends UnitTestCase {

  private const ENVELOPE = '10000000-0000-4000-8000-000000000001';
  private const MAPPING = '20000000-0000-4000-8000-000000000001';
  private const USER = '30000000-0000-4000-8000-000000000001';
  private const CREATOR = '30000000-0000-4000-8000-000000000002';
  private const PARAGRAPH = '40000000-0000-4000-8000-000000000001';
  private const FILE = '50000000-0000-4000-8000-000000000001';
  private const PROFILE = '60000000-0000-4000-8000-000000000001';
  private const DIGEST = 'a3f184bb10644f64d71924b271e00debc4bf4e740d4148f5d033e7ea315451a0';
  private const OTHER_DIGEST = 'c2e5476500d5a534c7d3f8868c2d989b60fc05cef2b0cd7cf4f5be61242825c1';
  private const FIELD = 'field_employee_documents';

  /**
   * A new decision advances only after all independent reports agree.
   */
  public function testVerificationRefusesChangedEvidenceAndStrictExpiry(): void {
    $policy = new SignedDocumentPolicy();
    $pending = $this->mapping('pending');
    $subject = $this->subject();
    $reports = $this->reports();
    $intent = $policy->verify($pending, $subject, $reports, 'verify-key', NULL, 99, TRUE);
    $this->assertSame('verify', $intent->operation);
    $this->assertSame(['notified', 'scanning', 'verified'], $intent->states);
    $this->assertSame(self::DIGEST, $intent->digest);

    foreach ([
      ['provider' => ['envelope' => self::MAPPING]],
      ['provider' => ['status' => 'sent']],
      ['provider' => ['signed_at' => NULL]],
      ['fetch' => ['envelope' => self::MAPPING]],
      ['fetch' => ['size' => 20971521]],
      ['fetch' => ['pdf' => FALSE]],
      ['scan' => ['digest' => self::OTHER_DIGEST]],
      ['scan' => ['status' => 'infected']],
    ] as $change) {
      $this->refused(fn() => $policy->verify($pending, $subject, array_replace_recursive($reports, $change), 'verify-key', NULL, 99, TRUE));
    }
    $this->refused(fn() => $policy->verify($pending, $subject, $reports, 'verify-key', NULL, 100, TRUE));
    $this->refused(fn() => $policy->verify($pending, $subject, $reports, 'verify-key', NULL, 99, FALSE));
    $this->refused(fn() => $policy->verify($pending, ['active' => FALSE] + $subject, $reports, 'verify-key', NULL, 99, TRUE));
    $this->refused(fn() => $policy->verify($pending, ['class' => 'contractor'] + $subject, $reports, 'verify-key', NULL, 99, TRUE));
  }

  /**
   * A verified retry compares the digest again even when the new scan is clean.
   */
  public function testVerifiedRetryCannotSubstituteAnotherCleanDocument(): void {
    $policy = new SignedDocumentPolicy();
    $mapping = $this->mapping('verified');
    $reports = $this->reports();
    $intent = $policy->verify($mapping, $this->subject(), $reports, 'verify-key', $mapping, 99, TRUE);
    $this->assertSame('verified_retry', $intent->operation);
    $this->assertSame([], $intent->states);
    $substitute = array_replace_recursive($reports, [
      'fetch' => ['digest' => self::OTHER_DIGEST],
      'scan' => ['digest' => self::OTHER_DIGEST],
    ]);
    $this->refused(fn() => $policy->verify($mapping, $this->subject(), $substitute, 'verify-key', $mapping, 99, TRUE));
    $this->refused(fn() => $policy->verify($mapping, $this->subject(), $reports, 'other-key', NULL, 99, TRUE));
    $this->refused(fn() => $policy->verify($mapping, $this->subject(), $reports, 'verify-key', NULL, 99, TRUE));
    $conflict = $this->mapping('failed', ['id' => 2, 'uuid' => self::PROFILE]);
    $this->refused(fn() => $policy->verify($this->mapping('pending'), $this->subject(), $reports, 'verify-key', $conflict, 99, TRUE));
    $retry = $policy->verify($this->mapping('failed'), $this->subject(), $reports, 'other-key', NULL, 99, TRUE);
    $this->assertSame(['notified', 'scanning', 'verified'], $retry->states);
    $this->assertSame('9:employee_nda', $retry->expected->row['open_slot']);
  }

  /**
   * Replacing an old NDA requires all three signals and preserves its pin.
   */
  public function testAppendKeepsOldPinsAndRequiresExplicitPermissions(): void {
    $policy = new SignedDocumentPolicy();
    $mapping = $this->mapping('verified');
    $old = $this->attachment('upload');
    foreach ([[FALSE, TRUE, TRUE], [TRUE, FALSE, TRUE], [TRUE, TRUE, FALSE]] as [$confirm, $canAttach, $canReplace]) {
      $this->refused(fn() => $policy->attach($mapping, $this->subject(), $this->reports(), [$old], 99, $confirm, $canAttach, $canReplace));
    }
    $intent = $policy->attach($mapping, $this->subject(), $this->reports(), [$old], 99, TRUE, TRUE, TRUE);
    $this->assertSame('append', $intent->operation);
    $this->assertSame(['attaching'], $intent->states);
    $this->assertSame([['target_id' => 12, 'target_revision_id' => 31]], $intent->existingPins);
    $this->assertSame(self::FIELD, $intent->appendField);
    $this->assertSame(1, $intent->appendDelta);
    $this->assertSame(219, $intent->leaseUntil);
    $this->refused(fn() => $policy->attach($mapping, $this->subject(), $this->reports(), [$this->attachment('docusign')], 99, TRUE, TRUE, TRUE));
  }

  /**
   * Equality is an expired mapping but still a live attaching lease.
   */
  public function testLeaseBoundaryAndTerminalSlots(): void {
    $policy = new SignedDocumentPolicy();
    $live = $this->mapping('attaching', ['lease_until' => 99, 'lease_file_hash' => self::DIGEST]);
    $this->refused(fn() => $policy->attach($live, $this->subject(), $this->reports(), [], 99, FALSE, TRUE, FALSE));
    $this->refused(fn() => $policy->terminate($live, 'revoked', 99, TRUE));
    $expired = $this->mapping('attaching', ['lease_until' => 98, 'lease_file_hash' => self::DIGEST]);
    $intent = $policy->attach($expired, $this->subject(), $this->reports(), [], 99, FALSE, TRUE, FALSE);
    $this->assertSame(219, $intent->leaseUntil);
    $this->assertSame(98, $intent->expected->row['lease_until']);
    $revoked = $policy->terminate($expired, 'revoked', 100, TRUE);
    $this->assertSame(['revoked'], $revoked->states);
    $this->assertNull($revoked->openSlot());
    $this->refused(fn() => $this->mapping('attached', ['open_slot' => '9:employee_nda']));
  }

  /**
   * Exact history keeps detached identities and refuses ambiguous provenance.
   */
  public function testHistoricalQualificationAndMetadataBoundary(): void {
    $mapping = $this->mapping('attached');
    $document = $this->attachment('docusign');
    $profile = [
      'id' => 9,
      'uuid' => self::PROFILE,
      'revision' => 31,
      'uid' => 9,
      'user_uuid' => self::USER,
      'bundle' => 'employee',
      'field_nda_date' => '2020-02-29',
    ];
    $history = HistorySnapshot::qualify([$mapping], [$document], [$profile]);
    $this->assertSame([$mapping->row], $history->inverseMappings());
    $this->assertSame([$profile], $history->ndaDates);
    $this->assertSame([1 => $document->edge], $history->attachmentLinks);
    $this->assertSame('2028-01-02', $document->fields['field_document_expires']);
    $this->assertSame([
      'field_evidence_source', 'field_envelope_ref', 'field_file_sha256', 'field_signed_at',
    ], array_keys($document->viewFields(9, self::USER, FALSE)));
    $this->assertSame([], $document->viewFields(9, self::CREATOR, FALSE));
    $this->assertSame($document->fields, $document->viewFields(1, self::CREATOR, TRUE));
    $this->refused(fn() => HistorySnapshot::qualify([$mapping], [], [$profile]));
    $this->refused(fn() => HistorySnapshot::qualify([$mapping], [$document, $this->attachment('docusign', 1)], [$profile]));
    $duplicate = $this->mapping('verified', ['id' => 2, 'uuid' => self::PROFILE]);
    $this->refused(fn() => HistorySnapshot::qualify([$mapping, $duplicate], [$document], []));
    $this->refused(fn() => $this->attachment('docusign', 0, 'other_class'));
  }

  /**
   * Retained field pins require every ordered delta from zero.
   */
  public function testHistoryRequiresContiguousOrderedDeltas(): void {
    $first = $this->attachment('upload');
    $second = $this->attachment('upload', 1);
    $this->assertSame([$first, $second], HistorySnapshot::qualify([], [$first, $second], [])->attachments);
    $this->refused(fn() => HistorySnapshot::qualify([], [$second], []));
    $this->refused(fn() => HistorySnapshot::qualify([], [$second, $first], []));
    $this->refused(fn() => HistorySnapshot::qualify([], [$first, $this->attachment('upload', 2)], []));
    $this->refused(fn() => HistorySnapshot::qualify([], [$first, $first], []));
  }

  /**
   * Termination needs an explicit grant and an open mapping.
   *
   * Generic SignedDocumentRolePermissions / operator-form deny path.
   * Host role YAML and form CSRF stay on the host.
   */
  public function testTerminateRequiresExplicitPermissionAndOpenState(): void {
    $policy = new SignedDocumentPolicy();
    $pending = $this->mapping('pending');
    $this->refused(
      fn() => $policy->terminate($pending, 'revoked', 99, FALSE),
    );
    $this->refused(
      fn() => $policy->terminate($pending, 'attached', 99, TRUE),
    );
    $this->refused(
      fn() => $policy->terminate(
        $this->mapping('attached'),
        'revoked',
        99,
        TRUE,
      ),
    );
    $intent = $policy->terminate($pending, 'dead', 99, TRUE);
    $this->assertSame('terminate', $intent->operation);
    $this->assertSame(['dead'], $intent->states);
  }

  /**
   * Attach refuses a pin that already holds the mapping envelope.
   *
   * Generic SignedDocumentAttachService envelope collision. Host
   * paragraphs, profile dates and field_user_type stay on the host.
   */
  public function testAttachRefusesExistingEnvelope(): void {
    $policy = new SignedDocumentPolicy();
    $mapping = $this->mapping('verified');
    $this->refused(fn() => $policy->attach(
      $mapping,
      $this->subject(),
      $this->reports(),
      [$this->attachment('docusign')],
      99,
      FALSE,
      TRUE,
      FALSE,
    ));
  }

  /**
   * Repeated managed file identities must carry the same protection snapshot.
   */
  public function testHistoryRefusesConflictingProtectionForTheSameFile(): void {
    $first = $this->attachment('upload');
    $second = $this->attachment('upload', 1);
    $conflict = $this->attachment('upload', 1, grantDigest: self::OTHER_DIGEST);
    $this->assertSame([$first, $second], HistorySnapshot::qualify([], [$first, $second], [])->attachments);
    $this->refused(fn() => HistorySnapshot::qualify([], [$first, $conflict], []));
  }

  /**
   * Builds one normalized legacy row with separate account identities.
   */
  private function mapping(string $state, array $changes = []): MappingSnapshot {
    $terminal = in_array($state, ['attached', 'dead', 'revoked'], TRUE);
    $verified = in_array($state, ['verified', 'attaching', 'attached'], TRUE);
    return MappingSnapshot::fromSource($changes + [
      'id' => 1,
      'uuid' => self::MAPPING,
      'envelope_ref' => self::ENVELOPE,
      'uid' => 9,
      'document_kind' => 'employee_nda',
      'state' => $state,
      'expires_at' => 100,
      'open_slot' => $terminal ? NULL : '9:employee_nda',
      'lease_until' => NULL,
      'lease_file_hash' => NULL,
      'file_sha256' => $verified ? self::DIGEST : NULL,
      'signed_at' => $verified ? 44 : NULL,
      'signer_count' => $verified ? 1 : NULL,
      'attach_on_verify' => FALSE,
      'idempotency_key' => $state === 'pending' ? NULL : 'verify-key',
      'created' => 12,
      'changed' => 13,
      'created_by' => 10,
    ], self::USER, self::CREATOR);
  }

  /**
   * Supplies type-checked facts from independent host evidence.
   */
  private function reports(): array {
    return [
      'provider' => ['envelope' => self::ENVELOPE, 'status' => 'completed', 'signed_at' => 44, 'signer_count' => 1],
      'fetch' => [
        'envelope' => self::ENVELOPE,
        'digest' => self::DIGEST,
        'signed_at' => 44,
        'pdf' => TRUE,
        'size' => 40,
      ],
      'scan' => ['digest' => self::DIGEST, 'status' => 'clean'],
    ];
  }

  /**
   * Supplies the freshly bound subject.
   */
  private function subject(): array {
    return ['uid' => 9, 'uuid' => self::USER, 'active' => TRUE, 'class' => 'employee'];
  }

  /**
   * Makes a distinct evidence edge with the original private file item.
   */
  private function attachment(
    string $source,
    int $delta = 0,
    string $class = 'staff_aal3',
    string $grantDigest = self::DIGEST,
  ): AttachmentSnapshot {
    $file = new EvidenceFileItem(new QualifiedFile(4, self::FILE, $class, $grantDigest), FALSE, 'Original description');
    return AttachmentSnapshot::fromSource([
      'uid' => 9,
      'uuid' => self::USER,
      'field' => self::FIELD,
      'delta' => $delta,
      'target_id' => 12,
      'target_revision_id' => 31,
    ], [
      'id' => 12,
      'uuid' => self::PARAGRAPH,
      'revision' => 31,
      'bundle' => 'p_staff_document',
      'parent_type' => 'user',
      'parent_id' => 9,
      'parent_field' => self::FIELD,
    ], [
      'field_staff_document_kind' => 'employee_nda',
      'field_staff_file' => $file->sourceValues(),
      'field_document_expires' => '2028-01-02',
      'field_evidence_source' => $source,
      'field_envelope_ref' => $source === 'upload' ? NULL : self::ENVELOPE,
      'field_file_sha256' => self::DIGEST,
      'field_signed_at' => '1970-01-01T00:00:44',
    ], $file);
  }

  /**
   * Checks a minimized refusal rather than a provider error payload.
   */
  private function refused(callable $operation): void {
    try {
      $operation();
      $this->fail('Expected a signed-document qualification refusal.');
    }
    catch (\UnexpectedValueException $exception) {
      $this->assertStringStartsWith('signed_document_', $exception->getMessage());
      $this->assertStringNotContainsString(self::ENVELOPE, $exception->getMessage());
    }
  }

}
