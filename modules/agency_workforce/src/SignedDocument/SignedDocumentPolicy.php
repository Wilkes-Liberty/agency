<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\SignedDocument;

/**
 * Decides verification, retry, attachment and termination without a writer.
 *
 * Host evidence must come from the same fenced source scope. These decisions
 * qualify metadata agreement and carry no assertion of signer authenticity.
 *
 * @internal
 */
final class SignedDocumentPolicy {

  private const MAX_BYTES = 20971520;

  private const LEASE_SECONDS = 120;

  /**
   * Qualifies a new verification or an exact retry of a verified mapping.
   */
  public function verify(
    MappingSnapshot $mapping,
    array $subject,
    array $reports,
    string $retryKey,
    ?MappingSnapshot $retryOwner,
    int $dbNow,
    bool $mayVerify,
  ): SignedDocumentIntent {
    $row = $mapping->row;
    Qualification::require($mayVerify, 'verification_permission');
    $this->requireSubject($mapping, $subject);
    $this->requireFresh($mapping, $dbNow);
    Qualification::require(Qualification::retryKey($retryKey), 'retry_key');
    Qualification::require($retryOwner === NULL
      ? $row['idempotency_key'] !== $retryKey
      : $retryOwner->row['idempotency_key'] === $retryKey && $row['idempotency_key'] === $retryKey
        && $retryOwner->row['id'] === $row['id'] && $retryOwner->row['uuid'] === $row['uuid'], 'retry_collision');
    $evidence = $this->verifiedEvidence($mapping, $reports);
    if ($row['state'] === 'verified') {
      Qualification::require($row['idempotency_key'] === $retryKey && $retryOwner !== NULL, 'retry_key');
      $this->requireRetainedEvidence($mapping, $evidence);
      return new SignedDocumentIntent(
        'verified_retry', $mapping, [], $retryKey, $evidence['digest'], $evidence['signed_at'], $evidence['signer_count'],
      );
    }
    Qualification::require(in_array($row['state'], ['pending', 'failed'], TRUE), 'verification_state');
    return new SignedDocumentIntent(
      'verify', $mapping, ['notified', 'scanning', 'verified'], $retryKey,
      $evidence['digest'], $evidence['signed_at'], $evidence['signer_count'],
    );
  }

  /**
   * Qualifies an append while preserving the full prior User pin list.
   *
   * @param \Drupal\agency_workforce\SignedDocument\MappingSnapshot $mapping
   *   Freshly qualified mapping state.
   * @param array $subject
   *   Fresh account identity and class evidence.
   * @param array $reports
   *   Completed envelope, gated PDF and matching scan evidence.
   * @param array<int, \Drupal\agency_workforce\SignedDocument\AttachmentSnapshot> $existing
   *   The complete ordered pin list on the exact subject and class field.
   * @param int $dbNow
   *   Time reported by the host database.
   * @param bool $confirmedReplace
   *   Explicit confirmation to append another document of the same kind.
   * @param bool $mayAttach
   *   An explicit current attach grant.
   * @param bool $mayReplace
   *   An explicit current replace grant.
   */
  public function attach(
    MappingSnapshot $mapping,
    array $subject,
    array $reports,
    array $existing,
    int $dbNow,
    bool $confirmedReplace,
    bool $mayAttach,
    bool $mayReplace,
  ): SignedDocumentIntent {
    Qualification::require($mayAttach, 'attachment_permission');
    $field = $this->requireSubject($mapping, $subject);
    $this->requireFresh($mapping, $dbNow);
    $row = $mapping->row;
    Qualification::require($row['state'] === 'verified' || (
      $row['state'] === 'attaching' && $row['lease_until'] !== NULL && $row['lease_until'] < $dbNow
      && $row['lease_file_hash'] === $row['file_sha256']
    ), 'attachment_lease');
    $evidence = $this->verifiedEvidence($mapping, $reports);
    $this->requireRetainedEvidence($mapping, $evidence);
    Qualification::require(array_is_list($existing), 'attachment_order');
    $pins = [];
    foreach ($existing as $delta => $attachment) {
      $edge = $attachment->edge;
      Qualification::require($edge['uid'] === $row['uid'] && $edge['uuid'] === $mapping->subjectUuid
        && $edge['field'] === $field && $edge['delta'] === $delta, 'attachment_order');
      Qualification::require($attachment->fields['field_envelope_ref'] !== $row['envelope_ref'], 'attachment_exists');
      if ($attachment->fields['field_staff_document_kind'] === $row['document_kind']) {
        Qualification::require($confirmedReplace && $mayReplace, 'replacement_permission');
      }
      $pins[] = [
        'target_id' => $edge['target_id'],
        'target_revision_id' => $edge['target_revision_id'],
      ];
    }
    Qualification::require($dbNow <= PHP_INT_MAX - self::LEASE_SECONDS, 'attachment_lease');
    return new SignedDocumentIntent(
      'append', $mapping, ['attaching'],
      retryKey: $row['idempotency_key'],
      digest: $evidence['digest'],
      signedAt: $evidence['signed_at'],
      signerCount: $evidence['signer_count'],
      leaseUntil: $dbNow + self::LEASE_SECONDS,
      appendField: $field,
      appendDelta: count($pins),
      existingPins: $pins,
    );
  }

  /**
   * Qualifies a terminal transition without intercepting a live attach lease.
   */
  public function terminate(MappingSnapshot $mapping, string $state, int $dbNow, bool $mayTerminate): SignedDocumentIntent {
    $row = $mapping->row;
    Qualification::require($mayTerminate && $dbNow >= 0 && in_array($state, ['dead', 'revoked'], TRUE)
      && in_array($row['state'], MappingSnapshot::OPEN_STATES, TRUE), 'termination_state');
    Qualification::require($row['state'] !== 'attaching' || $row['lease_until'] === NULL
      || $row['lease_until'] < $dbNow, 'attachment_lease');
    return new SignedDocumentIntent('terminate', $mapping, [$state]);
  }

  /**
   * Confirms a fresh account binding, classification and class-specific field.
   */
  private function requireSubject(MappingSnapshot $mapping, array $subject): string {
    Qualification::keys($subject, ['uid', 'uuid', 'class', 'active'], 'subject_binding');
    $class = $subject['class'];
    Qualification::require($subject['active'] === TRUE && $subject['uid'] === $mapping->row['uid']
      && $subject['uuid'] === $mapping->subjectUuid && is_string($class)
      && (MappingSnapshot::CLASS_KINDS[$class] ?? NULL) === $mapping->row['document_kind'], 'subject_binding');
    return AttachmentSnapshot::USER_FIELDS[$class];
  }

  /**
   * Refuses an expired mapping, including equality on the database clock.
   */
  private function requireFresh(MappingSnapshot $mapping, int $dbNow): void {
    Qualification::require($dbNow >= 0 && $dbNow < $mapping->row['expires_at'], 'mapping_expired');
  }

  /**
   * Checks separate provider, fetch gate and scanner evidence.
   *
   * @return array{digest: string, signed_at: ?int, signer_count: int}
   *   Only the matching verified values needed by the intent.
   */
  private function verifiedEvidence(MappingSnapshot $mapping, array $reports): array {
    Qualification::keys($reports, ['provider', 'fetch', 'scan'], 'reports');
    ['provider' => $provider, 'fetch' => $fetch, 'scan' => $scan] = $reports;
    Qualification::require(is_array($provider) && is_array($fetch) && is_array($scan), 'reports');
    Qualification::keys($provider, ['envelope', 'status', 'signed_at', 'signer_count'], 'provider_report');
    Qualification::keys($fetch, ['envelope', 'digest', 'signed_at', 'pdf', 'size'], 'fetch_report');
    Qualification::keys($scan, ['status', 'digest'], 'scan_report');
    $envelope = $mapping->row['envelope_ref'];
    Qualification::require($provider['envelope'] === $envelope && $provider['status'] === 'completed'
      && ($provider['signed_at'] === NULL || Qualification::quantity($provider['signed_at']))
      && Qualification::quantity($provider['signer_count']), 'provider_report');
    Qualification::require($fetch['envelope'] === $envelope && $fetch['signed_at'] === $provider['signed_at']
      && $fetch['pdf'] === TRUE && is_int($fetch['size']) && $fetch['size'] >= 5 && $fetch['size'] <= self::MAX_BYTES
      && Qualification::digest($fetch['digest']), 'fetch_report');
    Qualification::require($scan['status'] === 'clean' && Qualification::digest($scan['digest'])
      && hash_equals($fetch['digest'], $scan['digest']), 'scan_report');
    return [
      'digest' => $fetch['digest'],
      'signed_at' => $provider['signed_at'],
      'signer_count' => $provider['signer_count'],
    ];
  }

  /**
   * Binds even a clean refetch to the document originally verified.
   */
  private function requireRetainedEvidence(MappingSnapshot $mapping, array $evidence): void {
    $row = $mapping->row;
    Qualification::require(is_string($row['file_sha256']) && hash_equals($row['file_sha256'], $evidence['digest'])
      && $row['signed_at'] === $evidence['signed_at'] && $row['signer_count'] === $evidence['signer_count'], 'digest_changed');
  }

}
