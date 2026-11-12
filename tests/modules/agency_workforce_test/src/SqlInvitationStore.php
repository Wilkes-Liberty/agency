<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\agency_workforce\InvitationRecord;
use Drupal\agency_workforce\InvitationStoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * First-party synthetic adapter for the existing serialized expirable format.
 */
final class SqlInvitationStore implements InvitationStoreInterface {

  /**
   * Source reads before a synthetic concurrent-contact mutation.
   */
  private int $reads = 0;

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function connection(): Connection {
    return $this->database;
  }

  /**
   * {@inheritdoc}
   */
  public function collection(): string {
    return $this->config->get('agency_workforce_test.settings')->get('collection') ?: 'synthetic.staff.invitation';
  }

  /**
   * {@inheritdoc}
   */
  public function lookupDigest(string $digest, bool $lock = FALSE): ?InvitationRecord {
    $query = $this->database->select('agency_workforce_test_invitation', 'i')->fields('i')
      ->condition('collection', $this->collection())->condition('name', $digest);
    if ($lock) {
      $query->forUpdate();
    }
    $row = $query->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $payload = unserialize($row['value'], ['allowed_classes' => FALSE]);
    if (!is_array($payload)) {
      throw new \RuntimeException('Synthetic corrupt record.');
    }
    $record = InvitationRecord::fromStorage($row['collection'], $row['name'], $payload, (int) $row['expire'], $row['value']);
    if (++$this->reads === 1 && $this->config->get('agency_workforce_test.settings')->get('fault') === 'changed_contact') {
      $payload['email'] = 'changed@example.test';
      $this->database->update('agency_workforce_test_invitation')->fields(['value' => serialize($payload)])
        ->condition('collection', $row['collection'])->condition('name', $row['name'])->execute();
    }
    return $record;
  }

  /**
   * {@inheritdoc}
   */
  public function insertDigest(InvitationRecord $record): void {
    if ($this->config->get('agency_workforce_test.settings')->get('fault') === 'insert_noop') {
      return;
    }
    $this->database->insert('agency_workforce_test_invitation')->fields([
      'collection' => $record->collection,
      'name' => $record->digest,
      'value' => serialize([
        'email' => $record->contact,
        'type' => $record->classification,
        'langcode' => $record->language,
      ]),
      'expire' => $record->expires,
    ])->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function consume(InvitationRecord $record): int {
    if ($this->config->get('agency_workforce_test.settings')->get('fault') === 'consume_noop') {
      return 1;
    }
    $expires = $record->expires;
    if ($this->config->get('agency_workforce_test.settings')->get('fault') === 'consume_zero') {
      $expires++;
    }
    return $this->database->delete('agency_workforce_test_invitation')
      ->condition('collection', $record->collection)->condition('name', $record->digest)
      ->condition('value', $record->version)->condition('expire', $expires)->execute();
  }

}
