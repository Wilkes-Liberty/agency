<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Input for updateProfileEvidence. Every field carries a marking.
 */
final class UpdateProfileEvidence {

  /**
   * Person UUID the evidence belongs to.
   */
  public readonly string $personUuid;

  /**
   * Allowlisted projection audience for the write.
   */
  public readonly ProfileAudience $audience;

  /**
   * Marked field names. Values are not stored on this scaffolding object.
   *
   * @var list<array{name: string, marking: FieldMarking}>
   */
  public readonly array $fields;

  /**
   * File Gate references, never file bodies.
   *
   * @var list<ProtectedFileRef>
   */
  public readonly array $files;

  /**
   * Constructs marked profile evidence after factory validation.
   *
   * @param string $personUuid
   *   Person UUID the evidence belongs to.
   * @param \Drupal\agency_staff\Workforce\ProfileAudience $audience
   *   Allowlisted projection audience for the write.
   * @param list<array{name: string, marking: FieldMarking}> $fields
   *   Marked field names.
   * @param list<ProtectedFileRef> $files
   *   File Gate references, never file bodies.
   */
  public function __construct(string $personUuid, ProfileAudience $audience, array $fields, array $files) {
    $this->personUuid = $personUuid;
    $this->audience = $audience;
    $this->fields = $fields;
    $this->files = $files;
  }

  /**
   * Validates marked evidence without accepting file bytes.
   */
  public static function fromArray(array $input): self {
    $fieldsInput = $input['fields'] ?? NULL;
    $filesInput = $input['files'] ?? [];
    if (!is_array($fieldsInput) || $fieldsInput === [] || !is_array($filesInput)) {
      throw new Refusal('invalid');
    }
    $audience = $input['audience'] ?? '';
    if (!is_string($audience)) {
      throw new Refusal('invalid');
    }
    $fields = [];
    foreach ($fieldsInput as $field) {
      if (!is_array($field) || isset($field['bytes']) || isset($field['content']) || isset($field['data'])) {
        throw new Refusal('invalid');
      }
      $name = WorkforceInput::text($field['name'] ?? '', 64);
      if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
        throw new Refusal('invalid');
      }
      $marking = $field['marking'] ?? '';
      if (!is_string($marking)) {
        throw new Refusal('invalid');
      }
      $fields[] = [
        'name' => $name,
        'marking' => new FieldMarking($marking),
      ];
    }
    $files = [];
    foreach ($filesInput as $file) {
      $files[] = ProtectedFileRef::fromUnknown($file);
    }
    return new self(
      WorkforceInput::uuid($input['person_uuid'] ?? NULL),
      new ProfileAudience($audience),
      $fields,
      $files,
    );
  }

}
