<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\agency_workforce\TransactionalStaffProvisionerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Real local user plus synthetic host profile, on the supplied transaction.
 */
final class LocalProvisioner implements TransactionalStaffProvisionerInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
  ) {}

  public function connection(): Connection {
    return $this->database;
  }

  public function accountExists(string $normalizedContact): bool {
    return (bool) $this->database->select('users_field_data', 'u')->condition('uid', 0, '>')
      ->where('LOWER(mail) = :contact', [':contact' => $normalizedContact])->countQuery()->execute()->fetchField();
  }

  public function provision(string $normalizedContact, string $classification, string $language, string $preferredName): string {
    $settings = $this->config->get('agency_workforce_test.settings');
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
    if (!$user instanceof UserInterface) {
      throw new \LogicException('Synthetic user type unavailable.');
    }
    $user->setPassword(bin2hex(random_bytes(32)));
    $user->save();
    if ($settings->get('fault') === 'profile') {
      throw new \RuntimeException('Synthetic profile failure.');
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
    return $settings->get('fault') === 'binding_long' ? str_repeat('x', 256) : 'user:' . $user->id();
  }

}
