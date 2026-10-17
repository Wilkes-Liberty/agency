<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Drupal\externalauth\AuthmapInterface;
use Drupal\user\UserInterface;

/**
 * Authmap double whose user-id arguments match the interface.
 *
 * The installed externalauth Authmap::getAll() omits the int type on its
 * user id, so a string id does not throw. Production decorates that service
 * with an implementation that keeps getAll(int). This double is that
 * signature, and it forwards to the real authmap after the type check.
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
  public function getAll(int $uid): array {
    return $this->inner->getAll($uid);
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
