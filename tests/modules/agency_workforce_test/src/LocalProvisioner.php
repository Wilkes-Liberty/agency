<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\agency_workforce\TransactionalStaffProvisionerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Real local user plus synthetic host profile, on the supplied transaction.
 */
final class LocalProvisioner implements TransactionalStaffProvisionerInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
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
  public function accountExists(string $normalizedContact): bool {
    return (bool) $this->database->select('users_field_data', 'u')->condition('uid', 0, '>')
      ->where('LOWER(mail) = :contact', [':contact' => $normalizedContact])->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function provision(string $normalizedContact, string $classification, string $language, string $preferredName): string {
    $settings = $this->config->get('agency_workforce_test.settings');
    if ($settings->get('fault') === 'provision_noop') {
      return 'user:999999';
    }
    if ($settings->get('fault') === 'user') {
      throw new \RuntimeException('Synthetic user failure.');
    }
    $user = $this->entities->getStorage('user')->create([
      'name' => 'synthetic-' . bin2hex(random_bytes(8)),
      'mail' => $normalizedContact,
      'status' => 1,
      'langcode' => $language,
      'preferred_langcode' => $language,
      'roles' => ['synthetic_' . $classification],
    ]);
    $user->setPassword(bin2hex(random_bytes(32)));
    $user->save();
    if ($settings->get('fault') === 'profile') {
      throw new \RuntimeException('Synthetic profile failure.');
    }
    if ($settings->get('fault') === 'profile_noop') {
      return 'user:' . $user->id();
    }
    $hold = $settings->get('hold_file');
    $release = $settings->get('release_file');
    if (is_string($hold) && $hold !== '' && is_string($release) && $release !== '') {
      touch($hold);
      $deadline = microtime(TRUE) + 20;
      while (!file_exists($release)) {
        if (microtime(TRUE) > $deadline) {
          throw new \RuntimeException('Synthetic hold timed out.');
        }
        usleep(20000);
      }
    }
    $this->database->insert('agency_workforce_test_profile')->fields([
      'uid' => (int) $user->id(),
      'classification' => $classification,
      'language' => $language,
    ])->execute();
    if ($settings->get('fault') === 'binding_wrong') {
      return 'user:2';
    }
    return $settings->get('fault') === 'binding_long' ? str_repeat('x', 256) : 'user:' . $user->id();
  }

  /**
   * {@inheritdoc}
   */
  public function verifyBinding(string $binding, string $normalizedContact, string $classification, string $language): bool {
    if (!preg_match('/^user:([1-9][0-9]*)$/D', $binding, $match)) {
      return FALSE;
    }
    $query = $this->database->select('users_field_data', 'u');
    $query->innerJoin('agency_workforce_test_profile', 'p', 'p.uid = u.uid');
    $query->condition('u.uid', (int) $match[1])->condition('u.default_langcode', 1)
      ->condition('u.status', 1)->condition('u.preferred_langcode', $language)
      ->where('LOWER(u.mail) = :contact', [':contact' => $normalizedContact])
      ->condition('p.classification', $classification)->condition('p.language', $language);
    if (!(bool) $query->countQuery()->execute()->fetchField()) {
      return FALSE;
    }
    $roles = $this->database->select('user__roles', 'r')->fields('r', ['roles_target_id'])
      ->condition('entity_id', (int) $match[1])->execute()->fetchCol();
    return $roles === ['synthetic_' . $classification];
  }

}
