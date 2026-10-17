<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\Core\KeyValueStore\DatabaseStorage;

/**
 * Keeps failed framework reads distinct from absent console metadata.
 */
final class StrictConsoleStorage extends DatabaseStorage {

  /**
   * {@inheritdoc}
   */
  public function getAllKeys(): iterable {
    return array_keys($this->getAll());
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    if (!$keys) {
      return [];
    }
    $rows = $this->connection->select($this->table, 'k')
      ->fields('k', ['name', 'value'])
      ->condition('collection', $this->collection)
      ->condition('name', $keys, 'IN')->execute();
    $values = [];
    foreach ($rows as $row) {
      $values[$row->name] = $this->serializer->decode($row->value);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function catchException(\Exception $e) {
    throw $e;
  }

}
