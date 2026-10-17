<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\externalauth\AuthmapInterface;
use Drupal\user\UserInterface;

/**
 * Keeps covered Externalauth mutations atomic with sticky host revocation.
 */
final class TrackedAuthmap implements AuthmapInterface {

  public function __construct(private readonly AuthmapInterface $inner, private readonly BindingHistory $history) {}

  /**
   * {@inheritdoc}
   */
  public function save(UserInterface $account, string $provider, string $authname, $data = NULL) {
    if (!in_array($provider, $this->history->providers(), TRUE)) {
      return $this->inner->save($account, $provider, $authname, $data);
    }
    return $this->history->mutate(function () use ($account, $provider, $authname, $data) {
      foreach ($this->history->mappings((int) $account->id(), $provider) as $prior) {
        if ($prior['authname'] !== $authname) {
          $this->history->revokeMapping((int) $prior['uid'], $provider);
          $this->history->observe($prior['authname'], (int) $prior['uid'], $provider, TRUE);
        }
      }
      $this->history->observe($authname, (int) $account->id(), $provider, FALSE);
      return $this->inner->save($account, $provider, $authname, $data);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function delete(int $uid, ?string $provider = NULL) {
    $provider = $provider === '' ? NULL : $provider;
    if ($provider !== NULL && !in_array($provider, $this->history->providers(), TRUE)) {
      return $this->inner->delete($uid, $provider);
    }
    return $this->history->mutate(function () use ($uid, $provider) {
      foreach ($this->history->mappings($uid, $provider) as $prior) {
        $this->history->revokeMapping($uid, $prior['provider']);
        $this->history->observe($prior['authname'], $uid, $prior['provider'], TRUE);
      }
      return $this->inner->delete($uid, $provider);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteProvider(string $provider) {
    if (!in_array($provider, $this->history->providers(), TRUE)) {
      return $this->inner->deleteProvider($provider);
    }
    return $this->history->mutate(function () use ($provider) {
      foreach ($this->history->mappings(provider: $provider) as $prior) {
        $this->history->revokeMapping((int) $prior['uid'], $provider);
        $this->history->observe($prior['authname'], (int) $prior['uid'], $provider, TRUE);
      }
      return $this->inner->deleteProvider($provider);
    });
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

}
