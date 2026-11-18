<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\ContactMigration;

/**
 * Holds a complete, private in-memory projection of reviewed contacts.
 *
 * @internal
 */
final readonly class ContactProjection {

  public function __construct(
    private string $host,
    private string $tenant,
    private array $roots,
    private array $users,
    private array $claims,
  ) {}

  /**
   * Returns the partitioned root histories, keyed by original contact UUID.
   */
  public function records(): array {
    return $this->roots;
  }

  /**
   * Reconstructs the original source value tree without loading entities.
   */
  public function inverse(): array {
    $contacts = [];
    foreach ($this->roots as $root) {
      $revisions = [];
      foreach ($root['revisions'] ?? $root['commercial_extension'] as $revision) {
        $items = $revision['fields'];
        $items['id'] = [['value' => $root['id']]];
        $items['uuid'] = [['value' => $root['uuid']]];
        $items['revision_id'] = [['value' => $revision['revision_id']]];
        foreach (['owner', 'client_user', 'revision_user', 'merged_into'] as $reference) {
          $original = $revision[$reference];
          $items[$reference] = $original === NULL ? [] : [['target_id' => $original['id']]];
        }

        $language = $revision['langcode'];
        $fields = [];
        foreach ($root['source_layout']['fields'] as $name => $definition) {
          $fields[$name] = [$language => $items[$name]];
        }
        $revisions[] = [
          'revision_id' => $revision['revision_id'],
          'default' => $revision['default'],
          'langcode' => $language,
          'languages' => [$language],
          'fields' => $fields,
        ];
      }
      $contacts[] = [
        'version' => 1,
        'source_type' => 'crm_contact',
        'id' => $root['id'],
        'uuid' => $root['uuid'],
        'default_revision_id' => $root['default_revision_id'],
        'layout' => $root['source_layout'],
        'revision_count' => count($revisions),
        'revisions' => $revisions,
      ];
    }
    return [
      'host' => $this->host,
      'tenant' => $this->tenant,
      'contacts' => $contacts,
      'users' => $this->users,
      'claims' => $this->claims,
    ];
  }

}
