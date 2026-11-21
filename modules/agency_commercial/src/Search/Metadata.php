<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\Search;

use Drupal\agency_commercial\Vocabulary;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Explicit private-search projection; never copies arbitrary entity fields.
 *
 * Indexes opportunity and activity only. Agency has no contact entity
 * type. Client-account profile fields stay on the host.
 */
final class Metadata {

  /**
   * Searchable metadata property types.
   */
  public const TYPES = [
    'title' => 'string',
    'record_type' => 'string',
    'owner' => 'integer',
    'stage' => 'string',
    'next_action_at' => 'integer',
    'changed' => 'integer',
  ];

  /**
   * Returns current metadata only when every searchable source is visible.
   *
   * Callers must load the current entity, never render stored index values
   * or raw hit counts. A hidden matching field can disclose data even if
   * its value is omitted from the result, so partial field access excludes
   * the whole hit.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Candidate record.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Viewer whose field access is required.
   *
   * @return array<string, mixed>|null
   *   Projection, or NULL when the type, entity or a field is hidden.
   */
  public static function visibleValues(
    ContentEntityInterface $entity,
    AccountInterface $account,
  ): ?array {
    $fields = match ($entity->getEntityTypeId()) {
      'agency_opportunity' => [
        'title',
        'organization_name',
        'owner',
        'stage',
        'next_action_at',
        'changed',
      ],
      'agency_activity' => [
        'type',
        'occurred_at',
        'changed',
      ],
      default => [],
    };
    if (!$fields || !$entity->access('view', $account)) {
      return NULL;
    }
    foreach ($fields as $field) {
      $hidden = !$entity->hasField($field)
        || !$entity->get($field)->access('view', $account);
      if ($hidden) {
        return NULL;
      }
    }
    return self::values($entity);
  }

  /**
   * Returns searchable metadata, excluding documents and free-form notes.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Candidate record.
   *
   * @return array<string, mixed>|null
   *   Projection, or NULL when the type is not indexed.
   */
  public static function values(ContentEntityInterface $entity): ?array {
    $type = $entity->getEntityTypeId();
    if (!in_array($type, Vocabulary::TYPES, TRUE)) {
      return NULL;
    }
    $read = static fn(string $name): string => $entity->hasField($name)
      ? (string) ($entity->get($name)->value ?? '') : '';
    $title = match ($type) {
      'agency_opportunity' => trim(
        $read('title') . ' ' . $read('organization_name'),
      ),
      'agency_activity' => trim(
        (Vocabulary::ACTIVITIES[$read('type')] ?? 'Activity')
        . ' ' . $read('occurred_at'),
      ),
      default => NULL,
    };
    if ($title === NULL) {
      return NULL;
    }
    $owner = 0;
    if ($type === 'agency_opportunity' && $entity->hasField('owner')) {
      $owner = (int) $entity->get('owner')->target_id;
    }
    $next_action = $type === 'agency_opportunity' ? $read('next_action_at') : '';
    $timestamp = $next_action !== '' ? strtotime($next_action . ' UTC') : FALSE;
    return [
      'title' => $title,
      'record_type' => $type,
      'owner' => $owner,
      'stage' => $type === 'agency_opportunity' ? $read('stage') : '',
      'next_action_at' => $timestamp === FALSE ? 0 : $timestamp,
      'changed' => (int) $read('changed'),
    ];
  }

}
