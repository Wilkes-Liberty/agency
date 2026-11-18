<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\ContactMigration;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Qualifies one contact population against reviewed mappings and occupancy.
 *
 * @internal
 */
final class ContactPreflight {

  private const ROOT_TYPES = [
    'person' => 'agency_commercial_contact',
    'organization' => 'agency_organization',
  ];

  /**
   * Fingerprints all stored revisions and their source layout and selection.
   */
  public static function watermark(
    #[\SensitiveParameter] array $snapshot,
    #[\SensitiveParameter] array $users,
  ): string {
    $bindings = [];
    foreach ($snapshot['revisions'] ?? [] as $revision) {
      $language = $revision['langcode'] ?? '';
      foreach (['owner', 'client_user', 'revision_user'] as $field) {
        $id = $revision['fields'][$field][$language][0]['target_id'] ?? NULL;
        if (is_int($id) && $id > 0) {
          $bindings[$id] = $users[$id] ?? NULL;
        }
      }
    }
    ksort($bindings, SORT_NUMERIC);
    return hash('sha256', serialize([$snapshot, $bindings]));
  }

  /**
   * Fingerprints the reviewed row, including its scope and classifications.
   */
  public static function reviewDigest(#[\SensitiveParameter] array $row): string {
    unset($row['review_digest']);
    ksort($row);
    if (is_array($row['person'] ?? NULL)) {
      ksort($row['person']);
    }
    return hash('sha256', serialize($row));
  }

  /**
   * Returns an immutable projection only if the whole partition qualifies.
   *
   * The host supplies an authorized value snapshot and independently trusted
   * reviewed rows for that host and tenant. A digest is an integrity check on
   * those rows. The caller must source them from its private review boundary.
   *
   * @param array $source
   *   Scoped contact histories, verified user identities and claim rows.
   * @param array $crosswalk
   *   Reviewed rows including source ID, UUID, scope, kind and watermark.
   * @param array $occupancy
   *   Each kind's existing IDs, UUIDs and revision IDs. This is a read input.
   */
  public static function preflight(
    #[\SensitiveParameter] array $source,
    #[\SensitiveParameter] array $crosswalk,
    array $occupancy,
  ): ContactProjection {
    self::assertSource($source);
    $reviews = self::reviewRows($source, $crosswalk);
    $occupied = self::occupancy($occupancy);
    $identities = $revisions = $uuids = [];
    foreach ($source['contacts'] as $snapshot) {
      $id = $snapshot['id'];
      $uuid = $snapshot['uuid'];
      $normalized = strtolower($uuid);
      if (isset($identities[$id]) || isset($uuids[$normalized])
        || isset($occupied['ids'][$id]) || isset($occupied['uuids'][$normalized])) {
        throw new CommercialRefusal('contact_identity_collision');
      }
      $identities[$id] = ['kind' => $reviews[$uuid]['kind'], 'uuid' => $uuid, 'id' => $id];
      $uuids[$normalized] = TRUE;
    }

    $roots = $edges = [];
    foreach ($source['contacts'] as $snapshot) {
      $id = $snapshot['id'];
      $review = $reviews[$snapshot['uuid']];
      $history = self::history($snapshot, $identities, $source['users']);
      foreach ($history as $revision) {
        $vid = $revision['revision_id'];
        if (isset($revisions[$vid]) || isset($occupied['revision_ids'][$vid])) {
          throw new CommercialRefusal('contact_revision_collision');
        }
        $revisions[$vid] = TRUE;
        if ($revision['merged_into'] !== NULL) {
          $edges[$id][$revision['merged_into']['id']] = TRUE;
        }
      }

      $organization = NULL;
      if ($review['kind'] === 'organization') {
        $organization = [
          'display_name' => $review['shareable_alias'],
          'visibility' => $review['shareable_alias'] === NULL ? 'private' : 'shareable',
          'status' => 'active',
          'kind' => NULL,
        ];
      }
      $revisionsKey = $review['kind'] === 'person' ? 'revisions' : 'commercial_extension';
      $roots[$snapshot['uuid']] = $identities[$id] + [
        'type' => self::ROOT_TYPES[$review['kind']],
        'person' => $review['person'],
        'organization' => $organization,
        'source_layout' => $snapshot['layout'],
        'default_revision_id' => $snapshot['default_revision_id'],
        $revisionsKey => array_column($history, NULL, 'revision_id'),
      ];
    }
    self::assertAcyclic($identities, $edges);
    self::assertClaims($source['claims'], $identities);

    return new ContactProjection($source['host'], $source['tenant'], $roots, $source['users'], $source['claims']);
  }

  /**
   * Checks the versioned source envelope and verified account map.
   */
  private static function assertSource(array $source): void {
    if (count($source) !== 5 || !is_array($source['contacts'] ?? NULL) || !array_is_list($source['contacts'])
      || !is_array($source['claims'] ?? NULL) || !array_is_list($source['claims'])
      || !is_array($source['users'] ?? NULL)) {
      throw new CommercialRefusal('contact_source_history_invalid');
    }
    $uuids = [];
    foreach ($source['users'] as $id => $uuid) {
      if (!is_int($id) || $id < 1 || !self::isUuid($uuid) || isset($uuids[strtolower($uuid)])) {
        throw new CommercialRefusal('contact_user_binding_unverified');
      }
      $uuids[strtolower($uuid)] = TRUE;
    }
  }

  /**
   * Validates the complete reviewed classification before projecting histories.
   */
  private static function reviewRows(array $source, array $crosswalk): array {
    foreach (['host', 'tenant'] as $scope) {
      if (!is_string($source[$scope] ?? NULL) || $source[$scope] === '') {
        throw new CommercialRefusal('contact_source_scope_invalid');
      }
    }
    $reviews = [];
    foreach ($crosswalk as $row) {
      if (!is_array($row) || count($row) !== 9 || ($row['host'] ?? NULL) !== $source['host'] || ($row['tenant'] ?? NULL) !== $source['tenant']
        || !self::isUuid($row['source_uuid'] ?? NULL) || !is_int($row['source_id'] ?? NULL) || $row['source_id'] < 1
        || !is_string($row['kind'] ?? NULL) || !isset(self::ROOT_TYPES[$row['kind']])
        || !is_string($row['watermark'] ?? NULL) || !is_string($row['review_digest'] ?? NULL)
        || !array_key_exists('person', $row) || !array_key_exists('shareable_alias', $row)
        || !hash_equals(self::reviewDigest($row), $row['review_digest'])) {
        throw new CommercialRefusal('contact_crosswalk_invalid');
      }
      $uuid = $row['source_uuid'];
      if (isset($reviews[$uuid])) {
        throw new CommercialRefusal('contact_crosswalk_conflicting');
      }
      self::assertReviewBindings($row, $source);
      $reviews[$uuid] = $row;
    }

    $matched = [];
    foreach ($source['contacts'] as $snapshot) {
      $uuid = $snapshot['uuid'] ?? NULL;
      if (!is_array($snapshot) || !self::isUuid($uuid) || !is_int($snapshot['id'] ?? NULL) || $snapshot['id'] < 1) {
        throw new CommercialRefusal('contact_source_history_invalid');
      }
      if (!isset($reviews[$uuid])) {
        throw new CommercialRefusal('contact_crosswalk_missing');
      }
      $row = $reviews[$uuid];
      if ($row['source_id'] !== $snapshot['id'] || !hash_equals(self::watermark($snapshot, $source['users']), $row['watermark'])) {
        throw new CommercialRefusal('contact_crosswalk_stale');
      }
      $matched[$uuid] = TRUE;
    }
    if (count($matched) !== count($reviews)) {
      throw new CommercialRefusal('contact_crosswalk_unmatched');
    }
    return $reviews;
  }

  /**
   * Accepts only explicitly reviewed, correctly scoped optional bindings.
   */
  private static function assertReviewBindings(array $row, array $source): void {
    $person = $row['person'];
    if ($person !== NULL && ($row['kind'] !== 'person'
      || !is_array($person) || count($person) !== 4
      || ($person['host'] ?? NULL) !== $source['host'] || ($person['tenant'] ?? NULL) !== $source['tenant']
      || !is_int($person['id'] ?? NULL) || $person['id'] < 1 || !self::isUuid($person['uuid'] ?? NULL))) {
      throw new CommercialRefusal('contact_person_binding_invalid');
    }
    $alias = $row['shareable_alias'];
    if ($alias !== NULL && ($row['kind'] !== 'organization' || !is_string($alias) || trim($alias) === '')) {
      throw new CommercialRefusal('contact_organization_alias_invalid');
    }
  }

  /**
   * Validates both target populations against the shared legacy namespaces.
   */
  private static function occupancy(array $occupancy): array {
    if (count($occupancy) !== count(self::ROOT_TYPES)) {
      throw new CommercialRefusal('contact_occupancy_unverified');
    }
    $occupied = ['ids' => [], 'uuids' => [], 'revision_ids' => []];
    foreach (array_keys(self::ROOT_TYPES) as $kind) {
      $population = $occupancy[$kind] ?? [];
      if (!is_array($population) || count($population) !== count($occupied)) {
        throw new CommercialRefusal('contact_occupancy_unverified');
      }
      foreach (array_keys($occupied) as $namespace) {
        $values = $population[$namespace] ?? NULL;
        if (!is_array($values) || !array_is_list($values)) {
          throw new CommercialRefusal('contact_occupancy_unverified');
        }
        foreach ($values as $value) {
          $uuid = $namespace === 'uuids';
          if ($uuid ? !self::isUuid($value) : (!is_int($value) || $value < 1)) {
            throw new CommercialRefusal('contact_occupancy_unverified');
          }
          $occupied[$namespace][$uuid ? strtolower($value) : $value] = TRUE;
        }
      }
    }
    return $occupied;
  }

  /**
   * Projects typed field values and verified reference bindings per revision.
   */
  private static function history(array $snapshot, array $identities, array $users): array {
    if (count($snapshot) !== 8 || ($snapshot['version'] ?? NULL) !== 1 || ($snapshot['source_type'] ?? NULL) !== 'crm_contact'
      || !is_array($snapshot['layout'] ?? NULL) || !is_array($snapshot['revisions'] ?? NULL)
      || !array_is_list($snapshot['revisions']) || ($snapshot['revision_count'] ?? NULL) !== count($snapshot['revisions'])
      || !is_int($snapshot['default_revision_id'] ?? NULL)
      || $snapshot['default_revision_id'] < 1) {
      throw new CommercialRefusal('contact_source_history_invalid');
    }
    ContactFields::assertLayout($snapshot['layout']);
    $fieldReferences = array_flip([
      'id',
      'uuid',
      'revision_id',
      'owner',
      'client_user',
      'revision_user',
      'merged_into',
    ]);
    $defaults = 0;
    $result = [];
    foreach ($snapshot['revisions'] as $revision) {
      $vid = $revision['revision_id'] ?? NULL;
      $default = $revision['default'] ?? NULL;
      if (!is_array($revision) || count($revision) !== 5 || !is_int($vid) || $vid < 1 || !is_bool($default) || $default !== ($vid === $snapshot['default_revision_id'])) {
        throw new CommercialRefusal('contact_source_history_invalid');
      }

      $items = ContactFields::items($revision);
      if ($items['id'] !== [['value' => $snapshot['id']]] || $items['uuid'] !== [['value' => $snapshot['uuid']]]
        || $items['revision_id'] !== [['value' => $vid]] || !is_bool($items['revision_default'][0]['value'] ?? NULL)
        || ($default && $items['revision_default'][0]['value'] !== TRUE)) {
        throw new CommercialRefusal('contact_source_history_invalid');
      }
      $defaults += (int) $default;

      $merged = self::referenceId($items['merged_into']);
      if ($merged !== NULL && !isset($identities[$merged])) {
        throw new CommercialRefusal('contact_merge_target_missing');
      }
      $owner = self::userBinding($items['owner'], $users);
      if ($owner === NULL) {
        throw new CommercialRefusal('contact_user_binding_unverified');
      }
      $result[] = [
        'revision_id' => $vid,
        'default' => $default,
        'langcode' => $revision['langcode'],
        'fields' => array_diff_key($items, $fieldReferences),
        'owner' => $owner,
        'client_user' => self::userBinding($items['client_user'], $users),
        'revision_user' => self::userBinding($items['revision_user'], $users, TRUE),
        'merged_into' => $merged === NULL ? NULL : $identities[$merged],
      ];
    }
    if ($defaults !== 1) {
      throw new CommercialRefusal('contact_source_history_invalid');
    }
    return $result;
  }

  /**
   * Preserves empty references and refuses unknown identifier representations.
   */
  private static function referenceId(array $items): ?int {
    if ($items === []) {
      return NULL;
    }
    $id = $items[0]['target_id'];
    if (!is_int($id) || $id < 0) {
      throw new CommercialRefusal('contact_source_history_invalid');
    }
    return $id;
  }

  /**
   * Retains the source account ID and its verified UUID.
   */
  private static function userBinding(array $items, array $users, bool $allowAnonymous = FALSE): ?array {
    $id = self::referenceId($items);
    if ($id === NULL) {
      return NULL;
    }
    if ($id === 0 && $allowAnonymous) {
      return ['id' => 0, 'uuid' => NULL];
    }
    if ($id === 0 || !self::isUuid($users[$id] ?? NULL)) {
      throw new CommercialRefusal('contact_user_binding_unverified');
    }
    return ['id' => $id, 'uuid' => $users[$id]];
  }

  /**
   * Validates every historical merge link before releasing the projection.
   */
  private static function assertAcyclic(array $identities, array $edges): void {
    $incoming = array_fill_keys(array_keys($identities), 0);
    foreach ($edges as $targets) {
      foreach (array_keys($targets) as $target) {
        $incoming[$target]++;
      }
    }
    $ready = array_keys($incoming, 0, TRUE);
    $remaining = count($incoming);
    while ($ready !== []) {
      $id = array_pop($ready);
      $remaining--;
      foreach (array_keys($edges[$id] ?? []) as $target) {
        if (--$incoming[$target] === 0) {
          $ready[] = $target;
        }
      }
    }
    if ($remaining !== 0) {
      throw new CommercialRefusal('contact_merge_cycle');
    }
  }

  /**
   * Preserves claim keys, text bytes, binding and source timestamps.
   */
  private static function assertClaims(array $claims, array $identities): void {
    $seen = [];
    foreach ($claims as $claim) {
      if (!is_array($claim) || count($claim) !== 4 || !is_string($claim['source_uuid'] ?? NULL)
        || !is_string($claim['claims'] ?? NULL) || !is_int($claim['created'] ?? NULL)
        || !is_int($claim['contact_id'] ?? NULL) || ($claim['contact_id'] !== 0 && !isset($identities[$claim['contact_id']]))
        || isset($seen[$claim['source_uuid']])) {
        throw new CommercialRefusal('contact_claims_invalid');
      }
      $seen[$claim['source_uuid']] = TRUE;
    }
  }

  /**
   * Checks canonical UUID shape while keeping the stored spelling.
   */
  private static function isUuid(mixed $value): bool {
    return is_string($value) && (bool) preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $value);
  }

}
