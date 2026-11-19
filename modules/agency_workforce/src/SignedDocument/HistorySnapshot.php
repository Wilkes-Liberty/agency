<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\SignedDocument;

/**
 * A read-only qualification of preserved signed-document source history.
 *
 * @internal
 */
final readonly class HistorySnapshot {

  private function __construct(
    public array $mappings,
    public array $attachments,
    public array $ndaDates,
    public array $attachmentLinks,
  ) {}

  /**
   * Qualifies uniqueness and exact provenance within one inventoried source.
   *
   * @param list<\Drupal\agency_workforce\SignedDocument\MappingSnapshot> $mappings
   *   Original mapping rows for the closed qualification scope.
   * @param list<\Drupal\agency_workforce\SignedDocument\AttachmentSnapshot> $attachments
   *   Retained user attachment edges and pinned Paragraph values.
   * @param list<array> $ndaDates
   *   Independently inventoried Profile revision date records.
   */
  public static function qualify(array $mappings, array $attachments, array $ndaDates): self {
    $keys = $users = $userIds = $byEnvelope = $links = [];
    foreach ($mappings as $mapping) {
      $row = $mapping->row;
      foreach (['id', 'uuid', 'envelope_ref', 'open_slot', 'idempotency_key'] as $key) {
        if ($row[$key] !== NULL) {
          Qualification::require(!isset($keys[$key][$row[$key]]), 'mapping_collision');
          $keys[$key][$row[$key]] = TRUE;
        }
      }
      self::bindIdentity($users, $userIds, $row['uid'], $mapping->subjectUuid);
      if ($mapping->creatorUuid !== NULL) {
        self::bindIdentity($users, $userIds, $row['created_by'], $mapping->creatorUuid);
      }
      $byEnvelope[$row['envelope_ref']] = $mapping;
    }
    $edges = $paragraphs = $paragraphIds = $revisions = $parents = $files = $fileIds = [];
    foreach ($attachments as $attachment) {
      $edge = $attachment->edge;
      $paragraph = $attachment->paragraph;
      $fields = $attachment->fields;
      self::bindIdentity($users, $userIds, $edge['uid'], $edge['uuid']);
      self::bindIdentity($paragraphs, $paragraphIds, $paragraph['id'], $paragraph['uuid']);
      Qualification::require(!isset($edges[$edge['uid']][$edge['field']][$edge['delta']]), 'attachment_collision');
      $edges[$edge['uid']][$edge['field']][$edge['delta']] = TRUE;
      $version = [$paragraph, $fields];
      $parent = [$edge['uid'], $edge['uuid'], $edge['field']];
      Qualification::require(($revisions[$paragraph['revision']] ?? $version) === $version
        && ($parents[$paragraph['id']] ?? $parent) === $parent, 'paragraph_collision');
      $revisions[$paragraph['revision']] = $version;
      $parents[$paragraph['id']] = $parent;
      if ($attachment->file !== NULL) {
        $file = $attachment->file->file;
        self::bindIdentity($files, $fileIds, $file->fid, $file->uuid);
      }
      if ($fields['field_evidence_source'] !== 'docusign') {
        continue;
      }
      $mapping = $byEnvelope[$fields['field_envelope_ref']] ?? NULL;
      Qualification::require($mapping !== NULL && self::matches($mapping, $attachment)
        && !isset($links[$mapping->row['id']]), 'attachment_provenance');
      $links[$mapping->row['id']] = $edge;
    }
    foreach ($mappings as $mapping) {
      Qualification::require($mapping->row['state'] !== 'attached' || isset($links[$mapping->row['id']]), 'attachment_provenance');
    }
    $profiles = $profileIds = $profileRevisions = [];
    foreach ($ndaDates as $date) {
      Qualification::keys($date, ['id', 'uuid', 'revision', 'uid', 'user_uuid', 'bundle', 'field_nda_date'], 'profile_fields');
      Qualification::require(Qualification::id($date['id']) && Qualification::id($date['revision'])
        && Qualification::uuid($date['uuid']) && Qualification::id($date['uid']) && Qualification::uuid($date['user_uuid'])
        && isset(MappingSnapshot::CLASS_KINDS[$date['bundle']])
        && ($date['field_nda_date'] === NULL || Qualification::date($date['field_nda_date'])), 'profile_date');
      self::bindIdentity($users, $userIds, $date['uid'], $date['user_uuid']);
      self::bindIdentity($profiles, $profileIds, $date['id'], $date['uuid']);
      Qualification::require(!isset($profileRevisions[$date['revision']]), 'profile_revision_collision');
      $profileRevisions[$date['revision']] = TRUE;
    }
    return new self($mappings, $attachments, $ndaDates, $links);
  }

  /**
   * Retains the original nonrevisionable source rows.
   */
  public function inverseMappings(): array {
    return array_map(static fn(MappingSnapshot $mapping): array => $mapping->row, $this->mappings);
  }

  /**
   * Refuses conflicting ID-to-UUID and UUID-to-ID bindings.
   */
  private static function bindIdentity(array &$byId, array &$byUuid, int $id, string $uuid): void {
    Qualification::require(($byId[$id] ?? $uuid) === $uuid && ($byUuid[$uuid] ?? $id) === $id, 'identity_collision');
    $byId[$id] = $uuid;
    $byUuid[$uuid] = $id;
  }

  /**
   * Confirms the exact source linkage available without a foreign key.
   */
  private static function matches(MappingSnapshot $mapping, AttachmentSnapshot $attachment): bool {
    $row = $mapping->row;
    $edge = $attachment->edge;
    $fields = $attachment->fields;
    $class = array_search($row['document_kind'], MappingSnapshot::CLASS_KINDS, TRUE);
    $signedAt = $row['signed_at'] === NULL ? NULL : gmdate('Y-m-d\TH:i:s', $row['signed_at']);
    return $row['state'] === 'attached'
      && $edge['uid'] === $row['uid'] && $edge['uuid'] === $mapping->subjectUuid
      && $edge['field'] === AttachmentSnapshot::USER_FIELDS[$class]
      && $fields['field_staff_document_kind'] === $row['document_kind']
      && $fields['field_file_sha256'] === $row['file_sha256'] && $fields['field_signed_at'] === $signedAt;
  }

}
