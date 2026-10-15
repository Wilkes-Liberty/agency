<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;

/**
 * Reads fresh default-SQL policy within a caller-owned maintenance transaction.
 *
 * @internal
 */
final class LockedRetentionPolicy {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $config,
    private readonly StorageInterface $active,
    private readonly Policy $policy,
    private readonly bool $supported = FALSE,
  ) {}

  /**
   * Captures stored/effective facts; execution additionally locks the SQL row.
   *
   * @phpstan-impure
   */
  public function capture(bool $lock): array {
    if (!$this->supported || !in_array($this->database->driver(), ['mysql', 'pgsql'], TRUE)) {
      throw new Refusal('retention_unavailable');
    }
    if (!$this->database->inTransaction()) {
      throw new \LogicException('Retention policy requires a caller-owned transaction.');
    }
    $query = $this->database->select('config', 'c')->fields('c', ['data'])
      ->condition('collection', '')->condition('name', 'agency.settings');
    if ($lock) {
      $query->forUpdate();
    }
    $bytes = $query->execute()->fetchField();
    $stored = $bytes === FALSE ? FALSE : $this->active->decode($bytes);
    if (!is_array($stored)) {
      throw new Refusal('policy_unavailable');
    }
    $this->config->reset('agency.settings');
    $configured = $this->config->get('agency.settings');
    if ($stored !== $configured->getOriginal('', FALSE)) {
      throw new Refusal('policy_unavailable');
    }
    $facts = $this->policy->retentionFacts();
    return [
      'stored_sha256' => hash('sha256', json_encode($stored, JSON_THROW_ON_ERROR)),
      'effective_sha256' => hash('sha256', json_encode($configured->get(), JSON_THROW_ON_ERROR)),
      'facts' => $facts,
    ];
  }

}
