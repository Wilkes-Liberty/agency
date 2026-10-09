<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Enforces uniqueness independently of application-level serialization.
 */
final class InternalSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $entity_type->getBaseTable();
    $keys = match ($entity_type->id()) {
      'agency_principal' => ['principal_key' => ['principal_key']],
      'agency_invitation' => ['token_hash' => ['token_hash']],
      'agency_entitlement' => ['relationship_principal' => ['relationship_uuid', 'principal_uuid']],
      default => [],
    };
    foreach ($keys as $name => $columns) {
      $schema[$table]['unique keys'][$name] = $columns;
    }
    return $schema;
  }

}
