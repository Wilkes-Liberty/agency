<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Drupal\externalauth\AuthmapInterface;
use Drupal\user\UserInterface;

/**
 * Authmap double whose user-id arguments match the interface.
 *
 * The installed externalauth Authmap::getAll() omits the int type on its
 * user id, so a string id does not throw. Drupal account ids are numeric
 * strings; production and this double cast before the inner authmap.
 */
final class IntTypedAuthmap implements AuthmapInterface {

  public function __construct(
    private readonly AuthmapInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(UserInterface $account, string $provider, string $authname, $data = NULL) {
    $this->inner->save($account, $provider, $authname, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function get(int $uid, string $provider) {
    return $this->inner->get($uid, $provider);
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthData(int $uid, string $provider) {
    return $this->inner->getAuthData($uid, $provider);
  }

  /**
   * {@inheritdoc}
   */
  public function getAll(int|string $uid): array {
    return $this->inner->getAll((int) $uid);
  }

  /**
   * {@inheritdoc}
   */
  public function getUid(string $authname, string $provider) {
    return $this->inner->getUid($authname, $provider);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(int $uid, ?string $provider = NULL) {
    $this->inner->delete($uid, $provider);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteProvider(string $provider) {
    $this->inner->deleteProvider($provider);
  }

}
