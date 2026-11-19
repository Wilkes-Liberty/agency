<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\SignedDocument;

use Drupal\agency_workforce\PersonnelFile\EvidenceFileItem;

/**
 * A User field edge to an exact staff-document Paragraph and file identity.
 *
 * @internal
 */
final readonly class AttachmentSnapshot {

  public const FILE_CLASS = 'staff_aal3';

  public const USER_FIELDS = [
    'employee' => 'field_employee_documents',
    'contractor' => 'field_contractor_documents',
  ];

  public const OWN_METADATA = [
    'field_evidence_source', 'field_envelope_ref', 'field_file_sha256', 'field_signed_at',
  ];

  public const FIELDS = [
    'field_staff_document_kind', 'field_staff_file', 'field_document_expires',
    ...self::OWN_METADATA,
  ];

  private const KINDS = [
    'offer_letter', 'w4', 'i9', 'benefits', 'employee_nda', 'resume', 'w9',
    'sow', 'contractor_agreement', 'coi', 'invoice_template', 'contractor_nda', 'other',
  ];

  private function __construct(
    public array $edge,
    public array $paragraph,
    public array $fields,
    public ?EvidenceFileItem $file,
  ) {}

  /**
   * Qualifies the edge, pinned Paragraph, fields and host-qualified file.
   */
  public static function fromSource(array $edge, array $paragraph, array $fields, ?EvidenceFileItem $file): self {
    Qualification::keys($edge, ['uid', 'uuid', 'field', 'delta', 'target_id', 'target_revision_id'], 'user_edge');
    Qualification::keys($paragraph, [
      'id', 'uuid', 'revision', 'bundle', 'parent_type', 'parent_id', 'parent_field',
    ], 'paragraph_identity');
    Qualification::keys($fields, self::FIELDS, 'paragraph_fields');
    Qualification::require(Qualification::id($edge['uid']) && Qualification::uuid($edge['uuid'])
      && in_array($edge['field'], self::USER_FIELDS, TRUE) && Qualification::quantity($edge['delta'])
      && Qualification::id($edge['target_id']) && Qualification::id($edge['target_revision_id']), 'user_edge');
    Qualification::require($paragraph['id'] === $edge['target_id'] && Qualification::uuid($paragraph['uuid'])
      && $paragraph['revision'] === $edge['target_revision_id'] && $paragraph['bundle'] === 'p_staff_document'
      && $paragraph['parent_type'] === 'user' && $paragraph['parent_id'] === $edge['uid']
      && $paragraph['parent_field'] === $edge['field'], 'paragraph_identity');
    $values = $fields['field_staff_file'];
    Qualification::require($file === NULL ? $values === NULL : (
      $file->file->grantClass === self::FILE_CLASS && is_array($values)
      && self::equalRow($values, $file->sourceValues())
    ), 'file_binding');
    Qualification::require(in_array($fields['field_staff_document_kind'], self::KINDS, TRUE)
      && in_array($fields['field_evidence_source'], [NULL, 'upload', 'docusign'], TRUE)
      && ($fields['field_document_expires'] === NULL || Qualification::date($fields['field_document_expires']))
      && ($fields['field_envelope_ref'] === NULL || Qualification::envelope($fields['field_envelope_ref']))
      && ($fields['field_file_sha256'] === NULL || Qualification::digest($fields['field_file_sha256']))
      && ($fields['field_signed_at'] === NULL || Qualification::date($fields['field_signed_at'], 'Y-m-d\TH:i:s')), 'paragraph_values');
    if ($fields['field_evidence_source'] === 'docusign') {
      Qualification::require($file !== NULL && $fields['field_envelope_ref'] !== NULL
        && $fields['field_file_sha256'] !== NULL, 'provider_provenance');
    }
    return new self($edge, $paragraph, $fields, $file);
  }

  /**
   * Applies only the reviewed own-subject field exception.
   */
  public function viewFields(int $viewerUid, string $viewerUuid, bool $mayManage): array {
    if ($mayManage) {
      return $this->fields;
    }
    if ($viewerUid !== $this->edge['uid'] || $viewerUuid !== $this->edge['uuid']) {
      return [];
    }
    return array_intersect_key($this->fields, array_flip(self::OWN_METADATA));
  }

  /**
   * Compares exact typed file properties independent of field item key order.
   */
  private static function equalRow(array $left, array $right): bool {
    ksort($left);
    ksort($right);
    return $left === $right;
  }

}
