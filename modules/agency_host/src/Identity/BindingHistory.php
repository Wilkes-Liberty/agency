<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Composer\InstalledVersions;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\agency_host\HostSettings;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Update\UpdateHookRegistry;

/**
 * Sticky host revocations and separately qualified identity-history coverage.
 */
final class BindingHistory implements BindingHistoryInterface {

  private const REVIEWED_WRITERS = [
    [
      'drupal/externalauth',
      'src/Authmap.php',
      'e6c98dd790128410e07f05ad0ae54aa4b8e05850fc1fa8bad8a9491d9126c93a',
    ],
    [
      'drupal/externalauth',
      'src/Form/AuthmapDeleteForm.php',
      'aebd9185d105c9f1445baf532a33c6419459d28914d5da6f808921ccf1543500',
    ],
    [
      'drupal/openid_connect',
      'openid_connect.install',
      '29c5cc805630aa882868cd9e0a284e8d391f0a4d81e33458f7973bc9e6a0bfb7',
    ],
    [
      'drupal/openid_connect',
      'src/Entity/OpenIDConnectClientEntity.php',
      'b856e68c823ed1ad248a4694ea0269f9993e5425df3e0268c6847629690cd532',
    ],
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly Settings $settings,
    private readonly ConfigFactoryInterface $config,
    private readonly ModuleHandlerInterface $modules,
    private readonly UpdateHookRegistry $updates,
    private readonly TimeInterface $time,
    private readonly QualificationLatchInterface $latch,
    private readonly StorageInterface $activeConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function providers(): array {
    $configured = $this->config->get('agency_host.settings')->get('binding_providers') ?? [];
    $providers = [];
    foreach ($configured as $provider) {
      if (is_string($provider) && preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $provider)) {
        $providers[] = $provider;
      }
    }
    return array_values(array_unique($providers));
  }

  /**
   * Canonical identity scope; changed configuration needs fresh qualification.
   */
  public function scope(): string {
    $identity = HostSettings::get($this->settings, 'identity', []);
    $issuer = $identity['issuer'] ?? NULL;
    $version = $identity['binding_version'] ?? NULL;
    if (!is_string($issuer) || !filter_var($issuer, FILTER_VALIDATE_URL)
      || !is_string($version) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $version)) {
      throw new IdentityDirectoryUnavailable('Binding history scope is unavailable.');
    }
    return hash('sha256', json_encode([$issuer, $version], JSON_THROW_ON_ERROR));
  }

  /**
   * Binds coverage to reviewed source, schema state and provider settings.
   */
  public function fingerprint(bool $includeMappings = TRUE): string {
    $versions = [];
    foreach (['drupal/externalauth' => '2.0.13', 'drupal/openid_connect' => '3.0.0-alpha9'] as $package => $reviewed) {
      $actual = InstalledVersions::getReference($package);
      if ($actual !== $reviewed) {
        throw new IdentityDirectoryUnavailable('Binding writer dependency requires review.');
      }
      $versions[$package] = $actual;
    }
    foreach (self::REVIEWED_WRITERS as [$package, $file, $expected]) {
      $path = InstalledVersions::getInstallPath($package) . '/' . $file;
      if (!is_file($path) || !hash_equals($expected, hash_file('sha256', $path))) {
        throw new IdentityDirectoryUnavailable('Binding writer source requires review.');
      }
    }
    if ($this->database->schema()->tableExists('openid_connect_authmap')) {
      throw new IdentityDirectoryUnavailable('Legacy binding reconciliation is incomplete.');
    }
    $schemas = [];
    // Keep the digest slot keyed chronicle_agency so already-qualified coverage
    // survives enabling agency_host. Do not add agency_host to this array.
    foreach (['externalauth', 'openid_connect', 'chronicle_agency'] as $module) {
      $enabled = $this->modules->moduleExists($module);
      $schema = $this->updates->getInstalledVersion($module);
      if ($enabled && (($module === 'openid_connect' && $schema < 30007)
        || ($module === 'chronicle_agency' && $schema < 10001)
        || ($module === 'externalauth' && $schema < 8104))) {
        throw new IdentityDirectoryUnavailable('Binding writer schema update is pending.');
      }
      // Update 10002 adds only invitation delivery storage. Preserve the exact
      // reviewed identity epoch without rewriting qualified history. Unknown
      // future schemas remain distinct and require their own review.
      if ($module === 'chronicle_agency' && $schema === 10002) {
        $schema = 10001;
      }
      $schemas[$module] = [$enabled, $schema];
    }
    $providers = [];
    foreach ($this->providers() as $provider) {
      $name = 'openid_connect.client.' . substr($provider, strlen('openid_connect.'));
      $stored = $this->activeConfig->read($name) ?: [];
      // Retain supported module/settings overrides while discarding this
      // factory's old ImmutableConfig object. The uncached storage projection
      // independently detects a concurrent save even if a cache backend lags.
      $client = $this->config->reset($name)->get($name);
      $authority = $storedAuthority = [];
      foreach ([
        'issuer_url', 'client_id', 'authorization_endpoint', 'token_endpoint',
        'userinfo_endpoint', 'iss_allowed_domains', 'provider_slug',
      ] as $key) {
        $authority[$key] = $client->get('settings.' . $key);
        $storedAuthority[$key] = $stored['settings'][$key] ?? NULL;
      }
      $providers[$provider] = [
        [$client->get('status'), $client->get('plugin'), $authority],
        [$stored['status'] ?? NULL, $stored['plugin'] ?? NULL, $storedAuthority],
      ];
    }
    $mappings = $includeMappings ? array_map(fn(array $mapping): array => [
      (int) $mapping['uid'],
      (string) $mapping['provider'],
      $this->subjectKey((string) $mapping['authname']),
    ], $this->mappings()) : [];
    usort($mappings, static fn(array $left, array $right): int => $left <=> $right);
    $token_name = $this->tokenName();
    $stored_tokens = $this->activeConfig->read($token_name) ?: [];
    $tokens = $this->config->reset($token_name)->get($token_name);
    $authority = [
      $tokens->get('paladin_issuer'), $tokens->get('paladin_audience'),
      $stored_tokens['paladin_issuer'] ?? NULL, $stored_tokens['paladin_audience'] ?? NULL,
      HostSettings::get($this->settings, 'identity', [])['binding_mode'] ?? NULL,
    ];
    return hash('sha256', json_encode([
      $this->scope(),
      $authority,
      hash_hmac('sha256', 'chronicle-agency-history-key-v1', Settings::getHashSalt()),
      $versions,
      $schemas,
      $providers,
      $mappings,
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Serializes history and current mapping reads against covered mutation.
   */
  public function authorize(string $subject, callable $currentBindings): bool {
    return $this->mutate(fn(): bool => $this->permits($subject) && $currentBindings());
  }

  /**
   * {@inheritdoc}
   */
  public function checkAuthority(): void {
    $this->mutate(static fn() => NULL);
  }

  /**
   * Inspects qualification without acquiring or resolving write guards.
   *
   * This diagnostic is never an authorization decision; writers must continue
   * through authorize() and its transaction fence.
   */
  public function qualificationReady(): bool {
    $coverage = $this->database->select('agency_host_binding_coverage', 'c')->fields('c')
      ->condition('scope', $this->scope())->execute()->fetchAssoc();
    return $coverage && (bool) $coverage['qualified']
      && !$this->latch->invalidated($coverage['epoch'])
      && hash_equals($coverage['fingerprint'], $this->fingerprint());
  }

  /**
   * Refuses unknown history and retains revocation after ordinary relinking.
   */
  private function permits(string $subject): bool {
    $scope = $this->scope();
    $coverage = $this->database->select('agency_host_binding_coverage', 'c')->fields('c')
      ->condition('scope', $scope)->execute()->fetchAssoc();
    if (!$coverage || !(bool) $coverage['qualified'] || !hash_equals($coverage['fingerprint'], $this->fingerprint())) {
      throw new IdentityDirectoryUnavailable('Binding history coverage is not qualified.');
    }
    $revoked = $this->database->select('agency_host_binding_history', 'h')->fields('h', ['revoked'])
      ->condition('id', $this->subjectKey($subject))->execute()->fetchField();
    return $revoked === FALSE || !(bool) $revoked;
  }

  /**
   * Serializes host history and the real authmap write on one connection.
   */
  public function mutate(callable $write): mixed {
    // Before the host schema update there is no qualified history to retain.
    // Existing identity flows remain usable; Agency authorization cannot pass.
    if (!$this->database->schema()->tableExists('agency_host_binding_coverage')) {
      return $write();
    }
    $transaction = $this->database->startTransaction();
    $guards = [];
    $clean = FALSE;
    $drift = FALSE;
    $callbackFailed = FALSE;
    try {
      $updated = $this->database->update('agency_host_binding_mutex')
        ->expression('value', '1 - value')->condition('id', 1)->execute();
      if ($updated !== 1) {
        throw new IdentityDirectoryUnavailable('Binding history write fence is unavailable.');
      }
      // Serialize raw and covered writes before taking the complete snapshot.
      if ($this->database->getConnectionOptions()['driver'] === 'pgsql') {
        $this->database->query('LOCK TABLE {authmap} IN SHARE ROW EXCLUSIVE MODE');
      }
      $coverages = $this->database->select('agency_host_binding_coverage', 'c')
        ->fields('c')->condition('qualified', 1)->execute()->fetchAll(FetchAs::Associative);
      foreach ($coverages as $coverage) {
        if (!$this->latch->invalidated($coverage['epoch'])) {
          // This independent commit precedes any observation of map/config
          // drift. Failed later invalidation cannot erase the unresolved guard.
          $guards[$coverage['epoch']] = $this->latch->arm($coverage['epoch']);
        }
      }
      $scope = $fingerprint = $authority = NULL;
      try {
        $scope = $this->scope();
        $fingerprint = $this->fingerprint();
        $authority = $this->fingerprint(FALSE);
      }
      catch (IdentityDirectoryUnavailable) {
        // Normal host writes remain possible, with Discovery unqualified.
      }
      $qualified = FALSE;
      foreach ($coverages as $coverage) {
        if (isset($guards[$coverage['epoch']]) && $coverage['scope'] === $scope
          && $fingerprint !== NULL && hash_equals($coverage['fingerprint'], $fingerprint)) {
          $qualified = TRUE;
        }
        else {
          $drift = TRUE;
          $this->latch->invalidate($coverage['epoch']);
          $this->database->update('agency_host_binding_coverage')
            ->fields(['qualified' => 0])->condition('scope', $coverage['scope'])->execute();
        }
      }
      $clean = !$drift;
      try {
        $result = $write();
      }
      catch (\Throwable $error) {
        $callbackFailed = TRUE;
        throw $error;
      }
      if ($qualified) {
        // A tracked map write cannot absorb concurrent authority/config drift.
        if (!hash_equals($authority, $this->fingerprint(FALSE))) {
          $drift = TRUE;
          foreach ($coverages as $coverage) {
            $this->latch->invalidate($coverage['epoch']);
          }
          $this->database->update('agency_host_binding_coverage')->fields(['qualified' => 0])->execute();
        }
        else {
          $this->database->update('agency_host_binding_coverage')
            ->fields(['fingerprint' => $this->fingerprint()])
            ->condition('scope', $scope)->condition('qualified', 1)->execute();
        }
      }
      if (!$drift) {
        foreach ($guards as $guard) {
          $this->latch->resolve($guard);
        }
        $guards = [];
      }
      return $result;
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      // A normal authmap uniqueness refusal with a confirmed clean rollback
      // does not destroy qualification. Unknown storage/rollback does.
      if ($clean && !$drift && $callbackFailed
        && ($error instanceof IntegrityConstraintViolationException || $error instanceof \InvalidArgumentException)) {
        foreach ($guards as $guard) {
          $this->latch->resolve($guard);
        }
      }
      throw $error;
    }
  }

  /**
   * Enumerates complete affected Externalauth rows, including missing user IDs.
   */
  public function mappings(?int $uid = NULL, ?string $provider = NULL): array {
    $query = $this->database->select('authmap', 'a')->fields('a', ['uid', 'provider', 'authname'])
      ->condition('provider', $this->providers(), 'IN');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    if ($provider !== NULL) {
      $query->condition('provider', $provider);
    }
    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Records the current scope when configured; never clears an earlier revoke.
   */
  public function observe(string $subject, int $uid, string $provider, bool $revoke): void {
    if (!$this->database->schema()->tableExists('agency_host_binding_history')) {
      return;
    }
    try {
      $scope = $this->scope();
    }
    catch (IdentityDirectoryUnavailable) {
      return;
    }
    $id = $this->subjectKey($subject);
    $existing = $this->database->select('agency_host_binding_history', 'h')->fields('h')
      ->condition('id', $id)->execute()->fetchAssoc();
    $this->database->merge('agency_host_binding_history')->key('id', $id)->fields([
      'scope' => $scope,
      'revoked' => (int) ($revoke || !empty($existing['revoked'])),
      'observed_at' => $existing['observed_at'] ?? $this->time->getCurrentTime(),
      'changed_at' => $this->time->getCurrentTime(),
    ])->execute();
    $association = hash('sha256', json_encode([$id, $uid, $provider], JSON_THROW_ON_ERROR));
    $this->database->merge('agency_host_binding_association')->key('id', $association)
      ->fields(['subject_key' => $id, 'uid' => $uid, 'provider' => $provider])->execute();
  }

  /**
   * Revokes earlier scopes too; restoring configuration cannot revive a link.
   */
  public function revokeMapping(int $uid, string $provider): void {
    if (!$this->database->schema()->tableExists('agency_host_binding_association')) {
      return;
    }
    $keys = $this->database->select('agency_host_binding_association', 'a')->fields('a', ['subject_key'])
      ->condition('uid', $uid)->condition('provider', $provider)->execute()->fetchCol();
    if ($keys !== []) {
      $this->database->update('agency_host_binding_history')
        ->fields(['revoked' => 1, 'changed_at' => $this->time->getCurrentTime()])
        ->condition('id', $keys, 'IN')->execute();
    }
  }

  /**
   * Hashes an unambiguous tuple without storing subject or issuer text.
   */
  private function subjectKey(string $subject): string {
    return hash_hmac('sha256', json_encode([$this->scope(), $subject], JSON_THROW_ON_ERROR), Settings::getHashSalt());
  }

  /**
   * Prefers copied host tokens, then the former config object.
   */
  private function tokenName(): string {
    $host = $this->activeConfig->read('agency_host.identity_tokens') ?: [];
    if (($host['paladin_issuer'] ?? '') !== '' || ($host['issuer'] ?? '') !== '') {
      return 'agency_host.identity_tokens';
    }
    return 'chronicle_agency.identity_tokens';
  }

}
