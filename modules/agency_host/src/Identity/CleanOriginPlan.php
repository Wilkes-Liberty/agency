<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\agency_host\HostSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Site\Settings;

/**
 * A bounded, read-only cutover snapshot; it cannot qualify or activate Agency.
 */
final class CleanOriginPlan {

  private const TABLES = [
    'authmap', 'agency_host_binding_history',
    'agency_host_binding_association', 'agency_host_binding_coverage',
    'agency_host_binding_guard', 'agency_host_binding_invalid',
    'agency_host_binding_mutex', 'agency_host_binding_origin',
  ];

  private const MAX_ROWS = 100000;

  public function __construct(
    private readonly Connection $database,
    private readonly Settings $settings,
    private readonly ConfigFactoryInterface $config,
    private readonly StorageInterface $activeConfig,
    private readonly BindingHistory $history,
    private readonly IdentityAuthority $authority,
    private readonly KeycloakDirectory $directory,
    private readonly CleanOriginReceipt $receipts,
  ) {}

  /**
   * Captures retained state in one PostgreSQL snapshot without writing guards.
   *
   * Digests are private comparison evidence, not proof that an issuer is new.
   * An apply implementation must revalidate under its own mutation fence.
   */
  public function report(string $receiptBytes): array {
    $transaction = NULL;
    try {
      if ($this->database->inTransaction() || $this->database->driver() !== 'pgsql'
        || intdiv((int) $this->database->query('SHOW server_version_num')->fetchField(), 10000) !== 16) {
        throw new \UnexpectedValueException();
      }
      $transaction = $this->database->startTransaction();
      // The PostgreSQL query wrapper creates a per-statement savepoint; SET
      // TRANSACTION must run directly at the root, before the first read.
      $this->database->prepareStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', [])->execute([]);
      $this->database->query("SET LOCAL statement_timeout = '5s'");
      $plan = $this->captureInFence($receiptBytes);
      $transaction->commitOrRelease();
      $transaction = NULL;
      return $plan;
    }
    catch (\Throwable) {
      if ($transaction !== NULL) {
        OriginTransaction::rollBack($transaction);
      }
      throw new IdentityDirectoryUnavailable('Clean-origin plan is unavailable.');
    }
  }

  /**
   * Captures under the qualifier's already acquired writer fence.
   *
   * Owned exclusions are only the qualifier's verified new coverage, guard and
   * ledger records. No other retained table can be excluded from comparison.
   */
  public function captureInFence(string $receiptBytes, array $owned = []): array {
    try {
      if (!$this->database->inTransaction()
        || array_diff(array_keys($owned), [
          'agency_host_binding_coverage',
          'agency_host_binding_guard',
          'agency_host_binding_origin',
        ])) {
        throw new \UnexpectedValueException();
      }
      foreach ($owned as $key) {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
          throw new \UnexpectedValueException();
        }
      }
      $name = 'agency_host.settings';
      $settings = $this->config->reset($name)->get($name);
      $stored = $this->activeConfig->read($name);
      if ($settings->get('enabled') !== FALSE || ($stored['enabled'] ?? NULL) !== FALSE
        || $this->authority->bindingMode() !== 'unbound_only') {
        throw new \UnexpectedValueException();
      }
      $this->config->reset('agency_host.identity_tokens');
      $issuer = $this->authority->issuer();
      $this->directory->configurationReady();
      $configurations = [];
      $required_clients = [];
      foreach ($this->history->providers() as $provider) {
        $required_clients[] = 'openid_connect.client.' . substr($provider, strlen('openid_connect.'));
      }
      $clients = array_unique(array_merge(
        $this->activeConfig->listAll('openid_connect.client.'),
        $required_clients,
      ));
      sort($clients, SORT_STRING);
      foreach ($clients as $name) {
        $pair = $this->configuration($name);
        // Check endpoint-only generic clients too, including disabled clients.
        foreach ($pair as $client) {
          if ($this->containsAuthority($client['settings'] ?? [], $issuer)) {
            throw new \UnexpectedValueException();
          }
        }
        $configurations[$name] = $pair;
      }
      $host_settings = $this->config->reset('agency_host.settings')->get('agency_host.settings');
      foreach ($host_settings->get('extra_containment_config') ?? [] as $visitor) {
        if (!is_string($visitor) || $visitor === '') {
          continue;
        }
        $configurations[$visitor] = $this->configuration($visitor);
        foreach ($configurations[$visitor] as $tokens) {
          // Match the ordinary validator's trim/trailing-slash normalization.
          if ($this->normalize($tokens['paladin_issuer'] ?? $tokens['issuer'] ?? '') === $issuer) {
            throw new \UnexpectedValueException();
          }
        }
      }
      foreach (['agency_host.settings', 'agency_host.identity_tokens'] as $name) {
        $configurations[$name] = $this->configuration($name);
      }
      foreach ($host_settings->get('extra_containment_settings') ?? [] as $settings_name) {
        if (!is_string($settings_name) || $settings_name === '') {
          continue;
        }
        $agent = $this->settings->get($settings_name, []);
        if (!is_array($agent) || $this->containsAuthority($agent, $issuer)) {
          throw new \UnexpectedValueException();
        }
        ksort($agent);
        $configurations[$settings_name] = $agent;
      }
      ksort($configurations);
      $identity = HostSettings::get($this->settings, 'identity');
      $tables = [];
      foreach (self::TABLES as $table) {
        $tables[$table] = $this->snapshot($table, $owned);
      }
      $fingerprint = $this->history->fingerprint();
      $receipt = $this->receipts->verify($receiptBytes, $issuer, $identity['binding_version'], $fingerprint);
      // Include every reader option, including a keyed digest of credentials.
      // Rotation changes the plan without disclosing a credential or its hash.
      ksort($identity);
      $plan = [
        'format' => 'agency-clean-origin-plan-v1',
        'qualification_performed' => FALSE,
        'receipt_sha256' => hash('sha256', $receiptBytes),
        'target_sha256' => $receipt['target_sha256'],
        'issuer_sha256' => hash('sha256', $issuer),
        'scope_sha256' => $this->history->scope(),
        'host_fingerprint' => $fingerprint,
        'source_sha256' => $this->sourceDigest(),
        'reader_sha256' => $this->digest($identity),
        'configuration_sha256' => $this->digest($configurations),
        'external_containment_locally_verified' => FALSE,
        'ui_composition_sha256' => $receipt['ui_composition_sha256'],
        'expires_at' => $receipt['expires_at'],
        'tables' => $tables,
      ];
      $plan['plan_sha256'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
      return $plan;
    }
    catch (\Throwable) {
      throw new IdentityDirectoryUnavailable('Clean-origin plan is unavailable.');
    }
  }

  /**
   * Includes every row and field, refusing oversized or unavailable snapshots.
   */
  private function snapshot(string $table, array $owned): array {
    $query = $this->database->select($table, 't')->fields('t')->range(0, self::MAX_ROWS + 1);
    if (isset($owned[$table])) {
      $key = match ($table) {
        'agency_host_binding_coverage' => 'scope',
        'agency_host_binding_guard' => 'id',
        'agency_host_binding_origin' => 'issuer_key',
      };
      $query->condition($key, $owned[$table], '<>');
    }
    $rows = $query->execute()->fetchAll(FetchAs::Associative);
    if (count($rows) > self::MAX_ROWS) {
      throw new \UnexpectedValueException();
    }
    $digests = [];
    foreach ($rows as $row) {
      ksort($row);
      $digests[] = $this->digest($row);
    }
    sort($digests, SORT_STRING);
    return ['rows' => count($rows), 'sha256' => $this->digest([$table, $digests])];
  }

  /**
   * Binds the preflight to deployed code and its locked dependency composition.
   */
  private function sourceDigest(): string {
    $files = [
      'composer.lock' => DRUPAL_ROOT . '/../composer.lock',
      'pgsql/TransactionManager.php' => DRUPAL_ROOT . '/core/modules/pgsql/src/Driver/Database/pgsql/TransactionManager.php',
      'core/TransactionManagerBase.php' => DRUPAL_ROOT . '/core/lib/Drupal/Core/Database/Transaction/TransactionManagerBase.php',
      'settings.php' => DRUPAL_ROOT . '/sites/default/settings.php',
    ];
    $optional = DRUPAL_ROOT . '/../patches.lock.json';
    if (is_file($optional)) {
      $files['patches.lock.json'] = $optional;
    }
    $modules = $this->config->get('agency_host.settings')->get('source_digest_modules') ?? ['agency_host'];
    $module_list = \Drupal::service('extension.list.module');
    foreach ($modules as $module) {
      if (!is_string($module) || $module === '' || !$module_list->exists($module)) {
        throw new \UnexpectedValueException();
      }
      $root = DRUPAL_ROOT . '/' . $module_list->getPath($module);
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (str_starts_with($relative, 'tests/') || !$file->isFile()) {
          continue;
        }
        if ($file->isLink() || count($files) >= 10000) {
          throw new \UnexpectedValueException();
        }
        $files[$module . '/' . $relative] = $file->getPathname();
      }
    }
    ksort($files);
    $digests = [];
    $bytes = 0;
    foreach ($files as $name => $file) {
      if (!is_file($file) || ($size = filesize($file)) === FALSE
        || ($bytes += $size) > 104857600
        || ($digest = hash_file('sha256', $file)) === FALSE) {
        throw new \UnexpectedValueException();
      }
      $digests[$name] = $digest;
    }
    return hash('sha256', json_encode($digests, JSON_THROW_ON_ERROR));
  }

  /**
   * Captures complete stored/effective objects, including status and endpoints.
   */
  private function configuration(string $name): array {
    return [
      'stored' => $this->activeConfig->read($name) ?: [],
      'effective' => $this->config->reset($name)->get($name)->get(),
    ];
  }

  /**
   * Refuses issuer or endpoint references to the new realm in Drupal clients.
   *
   * Scheme, host and the default port are canonical, so an equivalent spelling
   * cannot hide a shared realm. The path remains case-sensitive.
   */
  private function containsAuthority(array $values, string $issuer): bool {
    $authority = $this->canonicalizeUrl($issuer);
    foreach ($values as $value) {
      if (is_array($value) && $this->containsAuthority($value, $authority)) {
        return TRUE;
      }
      if (is_string($value)) {
        $normalized = $this->canonicalizeUrl($value);
        if ($normalized === $authority
          || str_starts_with($normalized, $authority . '/')) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Compares scheme, host, default port and path instead of raw spelling.
   */
  private function canonicalizeUrl(string $value): string {
    $trimmed = rtrim(trim($value), '/');
    $parts = parse_url($trimmed);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
      || $parts['host'] === '') {
      return $trimmed;
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = $parts['port'] ?? NULL;
    if (($scheme === 'https' && $port === 443)
      || ($scheme === 'http' && $port === 80)) {
      $port = NULL;
    }
    $path = rtrim($parts['path'] ?? '', '/');
    return $scheme . '://' . $host
      . ($port === NULL ? '' : ':' . $port) . $path;
  }

  /**
   * Uses the ordinary access-token validator's issuer normalization.
   */
  private function normalize(mixed $value): string {
    return is_string($value) ? rtrim(trim($value), '/') : '';
  }

  /**
   * Keeps row values and low-entropy identities out of report and log output.
   */
  private function digest(array $value): string {
    return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), Settings::getHashSalt());
  }

}
