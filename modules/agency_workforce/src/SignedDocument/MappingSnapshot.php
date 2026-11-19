<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\SignedDocument;

/**
 * Exact qualified row for a nonrevisionable signed-document mapping.
 *
 * @internal
 */
final readonly class MappingSnapshot {

  public const CLASS_KINDS = [
    'employee' => 'employee_nda',
    'contractor' => 'contractor_nda',
  ];

  public const OPEN_STATES = [
    'pending', 'notified', 'scanning', 'verified', 'attaching', 'failed',
  ];

  public const TERMINAL_STATES = ['attached', 'dead', 'revoked'];

  private const FIELDS = [
    'id', 'uuid', 'envelope_ref', 'uid', 'document_kind', 'state',
    'expires_at', 'open_slot', 'lease_until', 'lease_file_hash', 'file_sha256',
    'signed_at', 'signer_count', 'attach_on_verify', 'idempotency_key',
    'created', 'changed', 'created_by',
  ];

  private function __construct(
    public array $row,
    public string $subjectUuid,
    public ?string $creatorUuid,
  ) {}

  /**
   * Accepts exact stored values with verified subject and creator UUIDs.
   */
  public static function fromSource(array $row, string $subjectUuid, ?string $creatorUuid): self {
    Qualification::keys($row, self::FIELDS, 'mapping_fields');
    Qualification::require(Qualification::id($row['id']) && Qualification::uuid($row['uuid'])
      && Qualification::envelope($row['envelope_ref']) && Qualification::id($row['uid'])
      && Qualification::uuid($subjectUuid) && in_array($row['document_kind'], self::CLASS_KINDS, TRUE), 'mapping_identity');
    $open = in_array($row['state'], self::OPEN_STATES, TRUE);
    Qualification::require(($open || in_array($row['state'], self::TERMINAL_STATES, TRUE))
      && $row['open_slot'] === ($open ? $row['uid'] . ':' . $row['document_kind'] : NULL), 'mapping_state');
    foreach (['expires_at', 'created', 'changed'] as $field) {
      Qualification::require(Qualification::quantity($row[$field]), 'mapping_time');
    }
    foreach (['signed_at', 'lease_until', 'signer_count'] as $field) {
      Qualification::require($row[$field] === NULL || Qualification::quantity($row[$field]), 'mapping_quantity');
    }
    foreach (['file_sha256', 'lease_file_hash'] as $field) {
      Qualification::require($row[$field] === NULL || Qualification::digest($row[$field]), 'mapping_digest');
    }
    Qualification::require(is_bool($row['attach_on_verify'])
      && ($row['idempotency_key'] === NULL || Qualification::retryKey($row['idempotency_key'])), 'mapping_settings');
    Qualification::require($row['created_by'] === NULL
      ? $creatorUuid === NULL
      : Qualification::id($row['created_by']) && Qualification::uuid($creatorUuid), 'creator_binding');
    return new self($row, $subjectUuid, $creatorUuid);
  }

}
