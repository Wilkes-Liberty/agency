<?php

declare(strict_types=1);

namespace Drupal\agency_host_synthetic;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Test-only I/O fault boundary; knows no Agency table, field or schema.
 */
final class SyntheticRecordingConnection extends Connection {

  public function __construct(private readonly Connection $real, private readonly StateInterface $state) {
    // Query construction is delegated; these satisfy the base invariant only.
    $this->identifierQuotes = ['"', '"'];
    parent::__construct($real->getClientConnection(), $real->getConnectionOptions());
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {
    throw new \LogicException('The synthetic adapter requires an existing connection.');
  }

  /**
   * {@inheritdoc}
   */
  public function insert($table, array $options = []) {
    if ($this->state->get('agency_synthetic.recording_failure', FALSE)) {
      throw new \RuntimeException('Synthetic recording connection failure.');
    }
    return $this->real->insert($table, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function inTransaction() {
    return $this->real->inTransaction();
  }

  /**
   * {@inheritdoc}
   */
  public function startTransaction($name = '') {
    return $this->real->startTransaction($name);
  }

  /**
   * {@inheritdoc}
   */
  public function select($table, $alias = NULL, array $options = []) {
    return $this->real->select($table, $alias, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function update($table, array $options = []) {
    return $this->real->update($table, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function upsert($table, array $options = []) {
    return $this->real->upsert($table, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function schema() {
    return $this->real->schema();
  }

  /**
   * {@inheritdoc}
   */
  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    return $this->real->queryRange($query, $from, $count, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function driver() {
    return $this->real->driver();
  }

  /**
   * {@inheritdoc}
   */
  public function databaseType() {
    return $this->real->databaseType();
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    throw new \LogicException('Synthetic recording cannot create a database.');
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    return $this->real->mapConditionOperator($operator);
  }

}
