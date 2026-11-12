<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Component\Uuid\Uuid;

/**
 * Validates immutable typed operation payloads against their SQL index identity.
 */
final class PostCutoverOperationEnvelope {

  /**
   * Decodes a qualified row without loading its possibly deleted live entity.
   */
  public static function decode(array $row, IdentityPreservingWriter $history): array {
    try {
      $envelope = json_decode($row['envelope'] ?? '', TRUE, 64, JSON_THROW_ON_ERROR);
      if (!is_array($envelope)
        || (int) ($row['envelope_version'] ?? 0) !== 1
        || ($envelope['version'] ?? NULL) !== 1
        || !in_array($envelope['kind'] ?? NULL, ['create', 'update', 'delete'], TRUE)
        || !is_string($envelope['domain'] ?? NULL)
        || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $envelope['domain'])
        || !is_int($envelope['generation'] ?? NULL) || $envelope['generation'] < 1
        || !is_string($envelope['operation_id'] ?? NULL) || !Uuid::isValid($envelope['operation_id'])
        || !is_string($envelope['request_hash'] ?? NULL) || !preg_match('/^[a-f0-9]{64}$/D', $envelope['request_hash'])
        || !is_string($envelope['entity_type'] ?? NULL)
        || !is_int($envelope['id'] ?? NULL) || $envelope['id'] < 1
        || !is_string($envelope['uuid'] ?? NULL) || !Uuid::isValid($envelope['uuid'])) {
        throw new \UnexpectedValueException();
      }
      foreach ([
        'domain' => 'domain',
        'generation' => 'authority_generation',
        'operation_id' => 'operation_id',
        'request_hash' => 'request_hash',
        'entity_type' => 'entity_type',
        'id' => 'entity_id',
        'uuid' => 'entity_uuid',
      ] as $field => $column) {
        if ((string) $envelope[$field] !== (string) ($row[$column] ?? '')) {
          throw new \UnexpectedValueException();
        }
      }
      if (!array_key_exists('before', $envelope) || !array_key_exists('after', $envelope)
        || (($envelope['kind'] === 'create') !== ($envelope['before'] === NULL))
        || (($envelope['kind'] === 'delete') !== ($envelope['after'] === NULL))) {
        throw new \UnexpectedValueException();
      }
      foreach (['before', 'after'] as $side) {
        if ($envelope[$side] === NULL) {
          continue;
        }
        $snapshot = $envelope[$side];
        if (!is_array($snapshot)
          || ($snapshot['source_type'] ?? NULL) !== $envelope['entity_type']
          || ($snapshot['id'] ?? NULL) !== $envelope['id']
          || ($snapshot['uuid'] ?? NULL) !== $envelope['uuid']) {
          throw new \UnexpectedValueException();
        }
        $history->validateHistorySnapshot($snapshot, $envelope['entity_type']);
      }
      return $envelope;
    }
    catch (\Throwable) {
      throw new MigrateRefusal('operation_unqualified');
    }
  }

  /**
   * Returns historical scalar identity, never a mutable live entity.
   */
  public static function receipt(array $row, array $envelope): array {
    return [
      'status' => 'completed',
      'wid' => (int) $row['wid'],
      'domain' => $envelope['domain'],
      'generation' => $envelope['generation'],
      'operation_id' => $envelope['operation_id'],
      'kind' => $envelope['kind'],
      'entity_type' => $envelope['entity_type'],
      'entity_id' => $envelope['id'],
      'entity_uuid' => $envelope['uuid'],
    ];
  }

}
