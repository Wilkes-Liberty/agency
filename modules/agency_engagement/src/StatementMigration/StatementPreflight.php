<?php

declare(strict_types=1);

namespace Drupal\agency_engagement\StatementMigration;

/**
 * Validates one statement and supports byte-exact, read-only inverse checks.
 *
 * @internal
 */
final class StatementPreflight {

  /**
   * The adapter reads exclusively within the caller's stable source snapshot.
   */
  public function __construct(private readonly StatementSourceInterface $source) {}

  /**
   * Qualifies the exact original row, ordered pins and membership set.
   */
  public function qualify(string $namespace, int $id): StatementProjection {
    $row = $this->source->document($id);
    self::require(trim($namespace) !== '' && $id > 0 && $row !== NULL && ($row['kind'] ?? NULL) === 'statement', 'document');
    self::shape($row, ['id', 'kind', 'actor', 'client_user', 'client_profile', 'created', 'body', 'sources'], 'document');
    self::require($row['id'] === $id && is_int($row['created']) && $row['created'] >= 0 && is_string($row['body']) && is_string($row['sources']), 'document');
    $subjects = [];
    foreach (['client_user' => 'user', 'client_profile' => 'profile', 'actor' => 'user'] as $field => $type) {
      self::require(is_int($row[$field]) && $row[$field] > 0, 'anchors');
      $uuid = $this->source->identity($type, $row[$field]);
      self::require(is_string($uuid) && $uuid !== '', 'anchors');
      $subjects[$field] = $uuid;
    }
    self::require($this->source->ownsProfile($row['client_profile'], $row['client_user']), 'anchors');

    $body = self::json($row['body'], 'body');
    $keys = ['groups', 'used_seconds', 'included_seconds', 'remaining_seconds', 'budget_reference'];
    if (array_key_exists('presentation', $body)) {
      $keys[] = 'presentation';
    }
    self::shape($body, $keys, 'body');
    $groups = self::rows($body['groups'], 'groups');
    $pins = self::rows(self::json($row['sources'], 'sources'), 'sources');

    $sources = $entryLines = [];
    foreach ($pins as $pin) {
      self::shape($pin, ['id', 'revision'], 'sources');
      self::require(is_int($pin['id']) && $pin['id'] > 0 && is_int($pin['revision']) && $pin['revision'] > 0 && !isset($sources[$pin['id']]), 'sources');
      $entry = $this->source->approvedEntry($pin['id'], $pin['revision'], $id, $row['created']);
      self::require($entry !== NULL && $entry['uuid'] !== '' && $entry['engagement'] > 0, 'historical approval');
      $sources[$pin['id']] = [
        'id' => $pin['id'],
        'revision' => $pin['revision'],
        'uuid' => $entry['uuid'],
        'engagement' => $entry['engagement'],
      ];
      $entryLines[$entry['engagement']][] = ['pin' => $pin, 'entry' => $entry];
    }
    $groupIds = array_column($groups, 'engagement');
    self::require($groupIds === array_keys($entryLines), 'groups');
    $membership = self::canonicalMembership($this->source->membership($id));
    self::require($membership === self::canonicalMembership($groupIds), 'membership');

    $presentation = array_key_exists('presentation', $body)
      ? StatementPresentation::qualify($body['presentation'], $groups, $body['remaining_seconds'] === NULL)
      : NULL;
    $used = 0;
    $engagements = [];
    foreach ($groups as $groupIndex => $group) {
      self::shape($group, ['engagement', 'title', 'lines'], 'groups');
      $work = $group['engagement'];
      $title = $presentation['prose'][$groupIndex]['title'] ?? NULL;
      $engagement = $this->source->engagement($work, $row['created'], $title['source_revision'] ?? NULL);
      self::require($engagement !== NULL && $engagement['uuid'] !== ''
        && $engagement['client_user'] === $row['client_user']
        && $engagement['client_profile'] === $row['client_profile']
        && $engagement['title'] === $group['title'], 'engagement anchors');
      self::checkProseSource($title, $engagement);
      $engagements[$work] = $engagement['uuid'];
      $lines = self::rows($group['lines'], 'lines');
      self::require(count($lines) === count($entryLines[$work]), 'lines');
      foreach ($lines as $lineIndex => $line) {
        self::shape($line, ['public_summary', 'seconds', 'date'], 'lines');
        ['pin' => $pin, 'entry' => $entry] = $entryLines[$work][$lineIndex];
        self::require($line['seconds'] === $entry['seconds'] && $entry['seconds'] > 0 && $entry['seconds'] <= 604800
          && $line['date'] === $entry['date'] && self::validDate($entry['date'])
          && $line['public_summary'] === $entry['public_summary']
          && trim($entry['public_summary']) !== '', 'lines');
        $prose = $presentation['prose'][$groupIndex]['lines'][$lineIndex]['public_summary'] ?? NULL;
        self::require($prose === NULL || $prose['source_revision'] === $pin['revision'], 'presentation source');
        self::checkProseSource($prose, $entry);
        $used += $entry['seconds'];
      }
    }
    $included = $body['included_seconds'];
    self::require(is_string($body['budget_reference']) && mb_strlen($body['budget_reference']) <= 255
      && ($included === NULL || (is_int($included) && $included >= 0 && $included <= 315360000 && trim($body['budget_reference']) !== ''))
      && $body['used_seconds'] === $used
      && $body['remaining_seconds'] === ($included === NULL ? NULL : $included - $used), 'seconds');
    $original = [
      'id' => $id,
      'kind' => 'statement',
      'client_user' => $row['client_user'],
      'client_profile' => $row['client_profile'],
      'actor' => $row['actor'],
      'created' => $row['created'],
      'body' => $row['body'],
      'sources' => $row['sources'],
    ];
    return new StatementProjection([$namespace, 'statement', $id], $original, $subjects, $body, array_values($sources), $membership, $engagements);
  }

  /**
   * Qualifies a separate receipt using its statement's original client anchor.
   */
  public function qualifyChallenge(StatementProjection $statement, int $id): ChallengeProjection {
    $row = $this->source->challenge($id);
    $document = $statement->inverse();
    self::require($id > 0 && $row !== NULL, 'challenge');
    self::shape($row, ['id', 'document', 'actor', 'retry_key', 'digest', 'reason', 'created'], 'challenge');
    self::require($row['id'] === $id && $row['document'] === $document['id'] && $row['actor'] === $document['client_user']
      && is_string($row['retry_key']) && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $row['retry_key']) === 1
      && is_string($row['digest']) && preg_match('/^[a-f0-9]{64}$/D', $row['digest']) === 1
      && is_string($row['reason']) && $row['reason'] !== '' && $row['reason'] === trim($row['reason']) && mb_strlen($row['reason']) <= 2000
      && is_int($row['created']) && $row['created'] >= $document['created'], 'challenge');
    // The writer hashes the untrimmed reason, which is absent from the row.
    $original = [
      'id' => $id,
      'document' => $document['id'],
      'actor' => $document['client_user'],
      'retry_key' => $row['retry_key'],
      'digest' => $row['digest'],
      'reason' => $row['reason'],
      'created' => $row['created'],
    ];
    $receipt = new ChallengeProjection($statement->identity, $statement->subjects['client_user'], $original);
    $this->checkChallengeReplay($receipt);
    return $receipt;
  }

  /**
   * Accepts an empty identity slot or a completely equal statement inverse.
   */
  public function checkDocumentReplay(StatementProjection $statement): void {
    $id = $statement->identity[2];
    $existing = $this->source->document($id);
    $membership = self::canonicalMembership($this->source->membership($id));
    self::require(
      $existing === NULL ? $membership === [] : (
        self::sameRow($existing, $statement->inverse()) && $membership === $statement->membership
      ), 'document collision',
    );
  }

  /**
   * Checks both receipt keys together before a future inverse can replay.
   */
  public function checkChallengeReplay(ChallengeProjection $challenge): void {
    $row = $challenge->inverse();
    $id = $this->source->challenge($row['id']);
    $key = $this->source->challengeByRetry($row['actor'], $row['retry_key']);
    self::require(
      ($id === NULL && $key === NULL)
      || ($id !== NULL && $key !== NULL && self::sameRow($id, $row) && self::sameRow($key, $row)), 'challenge collision',
    );
  }

  /**
   * Refuses repeated membership IDs and canonicalizes the original set.
   */
  private static function canonicalMembership(array $ids): array {
    $seen = [];
    foreach ($ids as $id) {
      self::require(is_int($id) && $id > 0 && !isset($seen[$id]), 'membership');
      $seen[$id] = TRUE;
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * Compares typed fields without assigning meaning to SQL column ordering.
   */
  private static function sameRow(array $actual, array $expected): bool {
    ksort($actual);
    ksort($expected);
    return $actual === $expected;
  }

  /**
   * Checks a frozen prose input pin without consulting today's translations.
   */
  private static function checkProseSource(?array $prose, array $source): void {
    self::require($prose === NULL || (
      $prose['source_langcode'] === $source['source_langcode']
      && hash_equals($source['source_hash'], $prose['source_hash'])
    ), 'presentation source');
  }

  /**
   * Strictly checks the shape and types of a bounded JSON row collection.
   */
  public static function rows(mixed $value, string $component): array {
    self::require(is_array($value) && array_is_list($value) && count($value) > 0 && count($value) <= 100, $component);
    foreach ($value as $row) {
      self::require(is_array($row), $component);
    }
    return $value;
  }

  /**
   * Requires exactly the reviewed field names in any serialization order.
   */
  public static function shape(mixed $value, array $keys, string $component): array {
    self::require(is_array($value) && count($value) === count($keys) && !array_diff($keys, array_keys($value)), $component);
    return $value;
  }

  /**
   * Decodes a bounded JSON depth while leaving the original bytes untouched.
   */
  private static function json(string $bytes, string $component): array {
    try {
      $value = json_decode($bytes, FALSE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \UnexpectedValueException("Invalid statement $component.");
    }
    // Decode objects separately from lists and count original member names.
    // PHP's decoder silently discards earlier values for a duplicate key.
    $properties = 0;
    $value = self::unpackJson($value, $properties, $component);
    $matches = preg_match_all('~"(?:[^"\\\\]|\\\\.)*+"(\s*:)?~s', $bytes, $tokens, PREG_SET_ORDER);
    self::require($matches !== FALSE && is_array($value)
      && count(array_filter($tokens, static fn(array $token): bool => isset($token[1]))) === $properties, $component);
    return $value;
  }

  /**
   * Converts named objects without accepting objects in list positions.
   */
  private static function unpackJson(mixed $value, int &$properties, string $component): mixed {
    if ($value instanceof \stdClass) {
      $value = (array) $value;
      self::require(!array_is_list($value), $component);
      $properties += count($value);
    }
    if (is_array($value)) {
      foreach ($value as &$item) {
        $item = self::unpackJson($item, $properties, $component);
      }
    }
    return $value;
  }

  /**
   * Matches an exact civil date without reference to today's timezone.
   */
  private static function validDate(string $value): bool {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
    return $date !== FALSE && $date->format('Y-m-d') === $value;
  }

  /**
   * Refuses with a data-free reason for the rejected component.
   *
   * @phpstan-assert true $condition
   */
  public static function require(bool $condition, string $component): void {
    if (!$condition) {
      throw new \UnexpectedValueException("Invalid statement $component.");
    }
  }

}
