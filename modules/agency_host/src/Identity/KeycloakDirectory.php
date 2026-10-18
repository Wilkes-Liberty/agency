<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\agency_host\HostSettings;
use Psr\Http\Message\ResponseInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;

/**
 * Performs bounded, uncached Keycloak Admin API subject readback.
 */
final class KeycloakDirectory implements IdentityDirectoryInterface, IdentityLookupInterface {

  public function __construct(
    private readonly ClientInterface $http,
    private readonly Settings $settings,
  ) {}

  /**
   * Validates reader settings without HTTP calls or returning credentials.
   */
  public function configurationReady(): bool {
    $this->configuration();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $subject): ?array {
    if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $subject)) {
      return NULL;
    }
    $record = $this->get('/' . rawurlencode($subject));
    if ($record === NULL) {
      return NULL;
    }
    if (!is_array($record) || ($record['id'] ?? NULL) !== $subject || !is_bool($record['enabled'] ?? NULL)) {
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    return [
      'subject' => $subject,
      'active' => $record['enabled'],
      'email' => is_string($record['email'] ?? NULL) ? trim($record['email']) : NULL,
      'email_verified' => ($record['emailVerified'] ?? FALSE) === TRUE,
      'service' => !empty($record['serviceAccountClientId']),
      'name' => trim((is_string($record['firstName'] ?? NULL) ? $record['firstName'] : '') . ' ' . (is_string($record['lastName'] ?? NULL) ? $record['lastName'] : '')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function findEmail(string $email): ?array {
    if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return NULL;
    }
    $records = $this->get('', ['email' => $email, 'exact' => 'true', 'max' => 2]);
    if (!is_array($records) || !array_is_list($records) || count($records) !== 1) {
      return NULL;
    }
    $record = $records[0];
    if (!is_array($record) || !is_string($record['id'] ?? NULL)
      || !preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $record['id'])
      || ($record['enabled'] ?? FALSE) !== TRUE || ($record['emailVerified'] ?? FALSE) !== TRUE
      || !empty($record['serviceAccountClientId']) || !is_string($record['email'] ?? NULL)
      || mb_strtolower(trim($record['email'])) !== mb_strtolower(trim($email))) {
      return NULL;
    }
    $fresh = $this->read($record['id']);
    if ($fresh === NULL || !$fresh['active'] || !$fresh['email_verified'] || $fresh['service']
      || !is_string($fresh['email']) || mb_strtolower($fresh['email']) !== mb_strtolower(trim($email))) {
      return NULL;
    }
    return $fresh;
  }

  /**
   * Makes one bounded authenticated lookup using the configured issuer realm.
   */
  private function get(string $suffix, array $query = []): ?array {
    $config = $this->configuration();
    try {
      $token_response = $this->http->request('POST', $config['token_url'], [
        'connect_timeout' => 2.0,
        'timeout' => 4.0,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'stream' => TRUE,
        'form_params' => [
          'grant_type' => 'client_credentials',
          'client_id' => $config['client_id'],
          'client_secret' => $config['client_secret'],
        ],
        'headers' => ['Accept' => 'application/json'],
      ]);
      if ($token_response->getStatusCode() !== 200) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
      $token_data = $this->decode($token_response);
      $token = is_array($token_data) ? ($token_data['access_token'] ?? NULL) : NULL;
      if (!is_string($token) || $token === '' || strlen($token) > 8192) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
      $response = $this->http->request('GET', sprintf(
        '%s/admin/realms/%s/users%s',
        $config['admin_base_url'],
        rawurlencode($config['realm']),
        $suffix,
      ), [
        'connect_timeout' => 2.0,
        'timeout' => 4.0,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'stream' => TRUE,
        'query' => $query,
        'headers' => [
          'Accept' => 'application/json',
          'Authorization' => 'Bearer ' . $token,
        ],
      ]);
      if ($response->getStatusCode() === 404) {
        return NULL;
      }
      if ($response->getStatusCode() !== 200) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
      $record = $this->decode($response);
    }
    catch (\Throwable $exception) {
      if ($exception instanceof IdentityDirectoryUnavailable) {
        throw $exception;
      }
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    return $record;
  }

  /**
   * Returns complete runtime-only connection settings.
   */
  private function configuration(): array {
    $config = HostSettings::get($this->settings, 'identity', []);
    if (!is_array($config)) {
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    $required = ['issuer', 'token_url', 'admin_base_url', 'realm', 'client_id', 'client_secret'];
    foreach ($required as $key) {
      if (!is_string($config[$key] ?? NULL) || trim($config[$key]) === '' || strlen($config[$key]) > 2048) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
    }
    foreach (['issuer', 'token_url', 'admin_base_url'] as $key) {
      $parts = parse_url($config[$key]);
      if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
    }
    if (!preg_match('/\A[A-Za-z0-9._-]+\z/', $config['realm'])) {
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    // An internal admin origin may differ, but it must read the issuer's realm.
    // Obtain the service credential only from the issuer's token endpoint.
    $realm_path = '/realms/' . rawurlencode($config['realm']);
    if (!str_ends_with($config['issuer'], $realm_path)
      || $config['token_url'] !== $config['issuer']
        . '/protocol/openid-connect/token') {
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    $config['admin_base_url'] = rtrim($config['admin_base_url'], '/');
    return $config;
  }

  /**
   * Bounds response reads before JSON parsing without exposing remote bodies.
   */
  private function decode(ResponseInterface $response): array {
    $stream = $response->getBody();
    $body = '';
    while (!$stream->eof() && strlen($body) <= 65536) {
      $chunk = $stream->read(min(8192, 65537 - strlen($body)));
      if ($chunk === '' && !$stream->eof()) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
      $body .= $chunk;
    }
    if (strlen($body) > 65536) {
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    $value = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
      throw new IdentityDirectoryUnavailable('identity_unavailable');
    }
    return $value;
  }

}
