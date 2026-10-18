<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Input for updateProfileEvidence.
 *
 * Writes use the own-profile audience only. Field names must match the
 * server marking map; callers cannot choose a weaker marking.
 */
final class UpdateProfileEvidence {

  /**
   * Server map for own-profile writes. Unmapped names refuse.
   *
   * display_name marking matches ontology Person.display_name.
   */
  public const REQUIRED_MARKINGS = [
    'display_name' => FieldMarking::PERSONAL,
  ];

  /**
   * Person UUID the evidence belongs to.
   */
  public readonly string $personUuid;

  /**
   * Own-profile write audience.
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
   *   Own-profile write audience.
   * @param list<array{name: string, marking: FieldMarking}> $fields
   *   Marked field names that already match REQUIRED_MARKINGS.
   * @param list<ProtectedFileRef> $files
   *   File Gate references, never file bodies.
   */
  public function __construct(
    string $personUuid,
    ProfileAudience $audience,
    array $fields,
    array $files,
  ) {
    $this->personUuid = WorkforceInput::uuid($personUuid);
    if ($audience->id !== ProfileAudience::OWN) {
      throw new Refusal('invalid');
    }
    if ($fields === []) {
      throw new Refusal('invalid');
    }
    $normalized = [];
    foreach ($fields as $field) {
      if (!is_array($field) || !isset($field['name'], $field['marking'])) {
        throw new Refusal('invalid');
      }
      if (!$field['marking'] instanceof FieldMarking) {
        throw new Refusal('invalid');
      }
      $name = WorkforceInput::text($field['name'], 64);
      $expected = self::REQUIRED_MARKINGS[$name] ?? NULL;
      if ($expected === NULL || $field['marking']->id !== $expected) {
        throw new Refusal('invalid');
      }
      $normalized[] = [
        'name' => $name,
        'marking' => $field['marking'],
      ];
    }
    foreach ($files as $file) {
      if (!$file instanceof ProtectedFileRef) {
        throw new Refusal('invalid');
      }
    }
    $this->audience = $audience;
    $this->fields = $normalized;
    $this->files = array_values($files);
  }

  /**
   * Validates marked evidence without accepting file bytes.
   */
  public static function fromArray(array $input): self {
    WorkforceInput::onlyKeys($input, [
      'person_uuid',
      'audience',
      'fields',
      'files',
    ]);
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
      if (!is_array($field)) {
        throw new Refusal('invalid');
      }
      WorkforceInput::onlyKeys($field, ['name', 'marking']);
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
