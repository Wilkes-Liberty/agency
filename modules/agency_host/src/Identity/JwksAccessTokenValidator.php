<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates participant JWTs against the configured issuer JWKS.
 *
 * Pattern matches File Gate assurance (JWKS via OIDC discovery, alg pin,
 * iss/aud/exp/sub) without depending on that module. JWKS is always
 * fetched from the configured issuer, never from the token's iss.
 */
final class JwksAccessTokenValidator implements AccessTokenValidatorInterface {

  /**
   * Default seconds between forced JWKS refetches for one issuer.
   */
  private const DEFAULT_FORCED_REFRESH_INTERVAL = 60;

  /**
   * Lease long enough for discovery plus JWKS at the 8s HTTP timeout.
   */
  private const REFRESH_LOCK_TIMEOUT = 20.0;

  /**
   * How long a racing request waits for the in-flight refresh.
   */
  private const REFRESH_LOCK_WAIT = 2;

  /**
   * Asymmetric algorithms accepted for participant access tokens.
   */
  private const ASYMMETRIC_ALGS = [
    'ES256',
    'ES256K',
    'ES384',
    'ES512',
    'RS256',
    'RS384',
    'RS512',
    'PS256',
    'PS384',
    'PS512',
    'EdDSA',
  ];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
    private readonly string $configurationName = 'agency_host.identity_tokens',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    if (!class_exists(JWT::class)) {
      return FALSE;
    }
    $audience = trim((string) $this->audience());
    return $this->configuredIssuer() !== '' && $audience !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string $jwt): ?string {
    $jwt = trim($jwt);
    if ($jwt === '' || !$this->isConfigured()) {
      return NULL;
    }

    $issuer = $this->configuredIssuer();
    $audience = trim((string) $this->audience());

    $alg = $this->jwtHeaderAlg($jwt);
    if ($alg === NULL) {
      return NULL;
    }

    $jwks = $this->loadJwks($issuer);
    if ($jwks === NULL) {
      return NULL;
    }

    $error = NULL;
    $claims = $this->decodeToken($jwt, $jwks, $alg, $error);
    if ($claims === NULL) {
      // Only refetch when the failure looks like key rotation. Expired, nbf,
      // and malformed tokens must not force outbound discovery.
      if ($error === NULL || !$this->isKeyRotationFailure($error)) {
        return NULL;
      }
      // Unknown kids are cheap for an unauthenticated caller. Stay inside the
      // per-issuer window instead of refetching discovery on every miss.
      if (!$this->shouldForceRefresh($issuer)) {
        return NULL;
      }
      $jwks = $this->refreshJwksForUnknownKid($issuer);
      if ($jwks === NULL) {
        return NULL;
      }
      $claims = $this->decodeToken($jwt, $jwks, $alg);
      if ($claims === NULL) {
        return NULL;
      }
    }

    $iss = isset($claims->iss) ? rtrim((string) $claims->iss, '/') : '';
    if ($iss === '' || $iss !== $issuer) {
      return NULL;
    }
    if (!in_array($audience, (array) ($claims->aud ?? []), TRUE)) {
      return NULL;
    }
    if (!isset($claims->exp)) {
      return NULL;
    }
    $sub = isset($claims->sub) ? trim((string) $claims->sub) : '';
    return $sub === '' ? NULL : $sub;
  }

  /**
   * Configured participant issuer, slash-trimmed.
   */
  private function configuredIssuer(): string {
    return rtrim($this->claim('issuer', 'paladin_issuer'), '/');
  }

  /**
   * Dedicated audience; historical paladin_audience remains readable.
   */
  private function audience(): string {
    return $this->claim('audience', 'paladin_audience');
  }

  /**
   * Prefers the generic key, then the historical fingerprint key.
   */
  private function claim(string $name, string $legacy): string {
    $tokens = $this->configFactory->get($this->configurationName);
    $value = trim((string) ($tokens->get($name) ?? ''));
    return $value !== '' ? $value : trim((string) ($tokens->get($legacy) ?? ''));
  }

  /**
   * Loads and caches the issuer JWKS via OIDC discovery.
   *
   * @param string $issuer
   *   The configured participant issuer.
   * @param bool $force
   *   When TRUE, skip the cached document and fetch again.
   *
   * @return array<string, mixed>|null
   *   The JWKS document, or NULL on failure.
   */
  private function loadJwks(string $issuer, bool $force = FALSE): ?array {
    $cid = $this->jwksCacheId($issuer);
    if (!$force && ($cached = $this->cache->get($cid))) {
      return is_array($cached->data) ? $cached->data : NULL;
    }
    $discovery_url = $issuer . '/.well-known/openid-configuration';
    if (!$this->isSecureEndpoint($discovery_url)) {
      $this->logger->error('Agency host: refusing token discovery over a non-HTTPS endpoint.');
      $this->cacheNegativeJwks($cid);
      return NULL;
    }
    try {
      $discovery = $this->httpGetJson($discovery_url);
      $jwks_uri = (string) ($discovery['jwks_uri'] ?? '');
      if ($jwks_uri === '' || !$this->isSecureEndpoint($jwks_uri)) {
        $this->cacheNegativeJwks($cid);
        return NULL;
      }
      $jwks = $this->httpGetJson($jwks_uri);
      if (empty($jwks['keys'])) {
        $this->cacheNegativeJwks($cid);
        return NULL;
      }
      $this->cache->set($cid, $jwks, $this->time->getRequestTime() + 3600);
      return $jwks;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Agency host: JWKS discovery failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      $this->cacheNegativeJwks($cid);
      return NULL;
    }
  }

  /**
   * Brief negative cache so an issuer outage cannot amplify outbound calls.
   */
  private function cacheNegativeJwks(string $cid): void {
    $this->cache->set($cid, FALSE, $this->time->getRequestTime() + 30);
  }

  /**
   * Signature-verifies the JWT against the JWKS.
   *
   * @param string $jwt
   *   The compact participant access token.
   * @param array<string, mixed> $jwks
   *   The issuer JWKS.
   * @param string $alg
   *   The pinned header algorithm.
   * @param \Throwable|null $error
   *   Set to the decode exception when verification fails.
   */
  private function decodeToken(string $jwt, array $jwks, string $alg, ?\Throwable &$error = NULL): ?object {
    $error = NULL;
    try {
      $keys = JWK::parseKeySet($jwks, $alg);
      $previous = JWT::$leeway;
      try {
        JWT::$leeway = 60;
        return JWT::decode($jwt, $keys);
      }
      finally {
        JWT::$leeway = $previous;
      }
    }
    catch (\Throwable $e) {
      $error = $e;
      $message = 'Agency host: participant token rejected: @msg';
      $context = ['@msg' => $e->getMessage()];
      if ($this->isKeyRotationFailure($e)) {
        $this->logger->warning($message, $context);
      }
      else {
        $this->logger->debug($message, $context);
      }
      return NULL;
    }
  }

  /**
   * Whether a decode failure is likely a rotated signing key, not a bad token.
   */
  private function isKeyRotationFailure(\Throwable $error): bool {
    if ($error instanceof ExpiredException || $error instanceof BeforeValidException) {
      return FALSE;
    }
    // A bad signature against a key already in the set is not rotation.
    // Only a missing kid forces a JWKS refetch.
    if ($error instanceof \UnexpectedValueException) {
      $message = strtolower($error->getMessage());
      return str_contains($message, 'kid') || str_contains($message, 'unable to lookup');
    }
    return FALSE;
  }

  /**
   * Whether an unknown kid may force another discovery plus JWKS fetch.
   *
   * The window is the last forced refresh for this issuer. A JWKS document
   * fetched inside the same interval is treated the same way, so a cache miss
   * does not immediately spend a second outbound pair.
   */
  private function shouldForceRefresh(string $issuer): bool {
    $interval = $this->forcedRefreshInterval();
    $now = $this->time->getRequestTime();
    $forced = $this->cache->get($this->forcedRefreshCacheId($issuer));
    if (is_object($forced) && ($now - (int) $forced->data) < $interval) {
      return FALSE;
    }
    $jwks = $this->cache->get($this->jwksCacheId($issuer));
    if (is_object($jwks) && ($now - (int) $jwks->created) < $interval) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Refetches JWKS once for this issuer, serialized across racing requests.
   *
   * The timestamp is written before the HTTP calls so later requests see the
   * window even while discovery is still in flight.
   *
   * @return array<string, mixed>|null
   *   The JWKS document after the refresh attempt, or NULL on failure.
   */
  private function refreshJwksForUnknownKid(string $issuer): ?array {
    $lock_name = $this->refreshLockName($issuer);
    if (!$this->lock->acquire($lock_name, self::REFRESH_LOCK_TIMEOUT)) {
      $this->lock->wait($lock_name, self::REFRESH_LOCK_WAIT);
      return $this->loadJwks($issuer);
    }
    try {
      if (!$this->shouldForceRefresh($issuer)) {
        return $this->loadJwks($issuer);
      }
      $this->recordForcedRefresh($issuer);
      return $this->loadJwks($issuer, TRUE);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Stores the last forced-refresh time for this issuer.
   */
  private function recordForcedRefresh(string $issuer): void {
    $now = $this->time->getRequestTime();
    $this->cache->set(
      $this->forcedRefreshCacheId($issuer),
      $now,
      $now + $this->forcedRefreshInterval(),
    );
  }

  /**
   * Minimum seconds between forced refetches; non-positive values use 60.
   */
  private function forcedRefreshInterval(): int {
    $configured = $this->configFactory->get($this->configurationName)
      ->get('jwks_forced_refresh_interval');
    $interval = is_numeric($configured) ? (int) $configured : 0;
    return $interval > 0 ? $interval : self::DEFAULT_FORCED_REFRESH_INTERVAL;
  }

  /**
   * Cache id for an issuer's JWKS.
   */
  private function jwksCacheId(string $issuer): string {
    return 'agency_host:jwks:' . hash('sha256', $issuer);
  }

  /**
   * Cache id for the last forced-refresh timestamp of an issuer.
   */
  private function forcedRefreshCacheId(string $issuer): string {
    return 'agency_host:jwks:forced:' . hash('sha256', $issuer);
  }

  /**
   * Lock name that serializes forced refreshes for one issuer.
   */
  private function refreshLockName(string $issuer): string {
    return 'agency_host.jwks_refresh:' . hash('sha256', $issuer);
  }

  /**
   * GET JSON from an HTTPS (or loopback) URL.
   *
   * @return array<string, mixed>
   *   Decoded JSON.
   */
  private function httpGetJson(string $url): array {
    $response = $this->httpClient->request('GET', $url, [
      'timeout' => 8,
      // Discovery/JWKS URLs are already HTTPS-checked. Do not follow a
      // redirect that could downgrade to HTTP.
      'allow_redirects' => FALSE,
      'headers' => ['Accept' => 'application/json'],
    ]);
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Non-JSON response from issuer discovery.');
    }
    return $data;
  }

  /**
   * HTTPS, or HTTP on loopback for local issuers.
   */
  private function isSecureEndpoint(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts)) {
      return FALSE;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
      return FALSE;
    }
    if ($scheme === 'https') {
      return TRUE;
    }
    return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], TRUE);
  }

  /**
   * Header alg when it is an allowed asymmetric algorithm.
   */
  private function jwtHeaderAlg(string $jwt): ?string {
    if (substr_count($jwt, '.') !== 2) {
      return NULL;
    }
    [$header_b64] = explode('.', $jwt, 2);
    $header = json_decode($this->base64UrlDecode($header_b64), TRUE);
    if (!is_array($header)) {
      return NULL;
    }
    $alg = (string) ($header['alg'] ?? '');
    return in_array($alg, self::ASYMMETRIC_ALGS, TRUE) ? $alg : NULL;
  }

  /**
   * Base64url decode.
   */
  private function base64UrlDecode(string $data): string {
    $normalized = strtr($data, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
      $normalized .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($normalized, TRUE);
    return $decoded === FALSE ? '' : $decoded;
  }

}
