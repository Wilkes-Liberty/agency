<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;

/**
 * Resolves stable, explicitly declared transaction participants.
 */
final class RollbackDispositionRegistry {

  /**
   * The declared provider ID for each protected domain.
   *
   * @var array<string, string>
   */
  private array $domains = [];

  /**
   * Lazy participant factories, indexed by their stable ID.
   *
   * @var array<string, \Closure>
   */
  private array $providers = [];

  /**
   * Registers one trusted service with one stable ID and one domain.
   */
  public function addProvider(\Closure $provider, mixed $provider_id = NULL, mixed $domain = NULL): void {
    if (!is_string($provider_id) || !self::validProviderId($provider_id)
      || !is_string($domain) || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $domain)
      || isset($this->providers[$provider_id]) || isset($this->domains[$domain])) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->providers[$provider_id] = $provider;
    $this->domains[$domain] = $provider_id;
  }

  /**
   * Checks the persisted provider identifier format independently of config.
   */
  public static function validProviderId(string $provider_id): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $provider_id);
  }

  /**
   * Returns the registered declaration for one domain, when present.
   */
  public function declaration(string $domain): ?string {
    return $this->domains[$domain] ?? NULL;
  }

  /**
   * Loads the exact declared provider bound by retained authority state.
   */
  public function resolve(string $domain, string $provider_id): RollbackDispositionInterface {
    if ($this->declaration($domain) !== $provider_id) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    try {
      $provider = ($this->providers[$provider_id])();
    }
    catch (\Throwable) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    if (!$provider instanceof RollbackDispositionInterface) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $provider;
  }

}
