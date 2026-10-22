<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\Identity\JwksAccessTokenValidator;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Proves unknown-kid JWKS refetches stay inside a per-issuer window.
 *
 * @group agency_host
 */
#[CoversClass(JwksAccessTokenValidator::class)]
#[Group('agency_host')]
final class JwksAccessTokenValidatorTest extends UnitTestCase {

  private const ISSUER = 'https://identity.example.test/realms/example';
  private const AUDIENCE = 'synthetic-agency';
  private const INTERVAL = 60;

  /**
   * Controlled public clock for window edges.
   */
  private int $now = 1700000000;

  /**
   * Previous JWT clock so tests do not leak a pinned timestamp.
   */
  private mixed $previousJwtTimestamp = NULL;

  /**
   * In-memory cache rows keyed by cid.
   *
   * @var array<string, object>
   */
  private array $store = [];

  /**
   * Current RSA signing material and its JWKS document.
   *
   * @var array{key: \OpenSSLAsymmetricKey, kid: string, jwks: array<string, mixed>}
   */
  private array $currentKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->store = [];
    $this->previousJwtTimestamp = JWT::$timestamp;
    $this->setClock(1700000000);
    $this->currentKey = $this->generateKey('current');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    JWT::$timestamp = $this->previousJwtTimestamp;
    parent::tearDown();
  }

  /**
   * Moves the public clock and the JWT library clock together.
   */
  private function setClock(int $timestamp): void {
    $this->now = $timestamp;
    JWT::$timestamp = $timestamp;
  }

  /**
   * An unknown kid inside the forced-refresh window does not hit the issuer.
   */
  public function testUnknownKidInsideWindowDoesNotRefetch(): void {
    $this->primeJwks($this->currentKey['jwks']);
    $this->primeForcedRefresh($this->now);
    $this->setClock($this->now + 30);
    $history = [];
    $validator = $this->validator($history, []);
    $this->assertNull($validator->validate($this->token('unknown')));
    $this->assertCount(0, $history);
  }

  /**
   * After the window, one unknown kid spends one discovery plus JWKS pair.
   */
  public function testUnknownKidAfterWindowRefetchesOnce(): void {
    $this->primeJwks($this->currentKey['jwks']);
    $this->primeForcedRefresh($this->now);
    $this->setClock($this->now + self::INTERVAL + 1);
    $history = [];
    $validator = $this->validator($history, [
      $this->discoveryResponse(),
      $this->jwksResponse($this->currentKey['jwks']),
    ]);
    $this->assertNull($validator->validate($this->token('unknown')));
    $this->assertCount(2, $history);
    $this->assertNull($validator->validate($this->token('still-unknown')));
    $this->assertCount(2, $history);
  }

  /**
   * One refetch after the window is enough to accept a rotated signing key.
   */
  public function testRotatedKeyAcceptedAfterOneRefetch(): void {
    $this->primeJwks($this->currentKey['jwks']);
    $this->primeForcedRefresh($this->now);
    $rotated = $this->generateKey('rotated');
    $this->setClock($this->now + self::INTERVAL + 1);
    $history = [];
    $validator = $this->validator($history, [
      $this->discoveryResponse(),
      $this->jwksResponse($rotated['jwks']),
    ]);
    $this->assertSame('synthetic-person', $validator->validate(
      $this->token('rotated', $rotated['key']),
    ));
    $this->assertCount(2, $history);
  }

  /**
   * A request that loses the refresh lock uses the cached set and does not fetch.
   */
  public function testUnknownKidDoesNotRefetchWhenRefreshLockIsHeld(): void {
    $this->primeJwks($this->currentKey['jwks']);
    $this->setClock($this->now + self::INTERVAL + 1);
    $history = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(FALSE);
    $lock->expects($this->once())->method('wait');
    $lock->expects($this->never())->method('release');
    $validator = $this->validator($history, [], $lock);
    $this->assertNull($validator->validate($this->token('unknown')));
    $this->assertCount(0, $history);
  }

  /**
   * Builds the validator over a synthetic clock, cache, lock and HTTP client.
   *
   * @param array<int, array<string, mixed>> $history
   *   Guzzle request history, filled by reference.
   * @param array<int, \GuzzleHttp\Psr7\Response> $responses
   *   Queued issuer responses.
   * @param \Drupal\Core\Lock\LockBackendInterface|null $lock
   *   Lock backend, or NULL for a lock that is always acquired.
   *
   * @return \Drupal\agency_host\Identity\JwksAccessTokenValidator
   *   The validator under test.
   */
  private function validator(array &$history, array $responses, ?LockBackendInterface $lock = NULL): JwksAccessTokenValidator {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(fn(string $cid) => $this->store[$cid] ?? FALSE);
    $cache->method('set')->willReturnCallback(function (string $cid, mixed $data): void {
      $this->store[$cid] = (object) [
        'data' => $data,
        'created' => $this->now,
      ];
    });
    $cache->method('delete')->willReturnCallback(function (string $cid): void {
      unset($this->store[$cid]);
    });
    if ($lock === NULL) {
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->method('acquire')->willReturn(TRUE);
    }
    $tokens = $this->createMock(ImmutableConfig::class);
    $tokens->method('get')->willReturnMap([
      ['issuer', self::ISSUER],
      ['audience', self::AUDIENCE],
      ['paladin_issuer', ''],
      ['paladin_audience', ''],
      ['jwks_forced_refresh_interval', self::INTERVAL],
    ]);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->with('agency_host.identity_tokens')->willReturn($tokens);
    return new JwksAccessTokenValidator(
      new Client(['handler' => $stack]),
      $cache,
      $time,
      new NullLogger(),
      $config,
      $lock,
    );
  }

  /**
   * Seeds the cached JWKS as if discovery already succeeded.
   *
   * @param array<string, mixed> $jwks
   *   The JWKS document.
   */
  private function primeJwks(array $jwks): void {
    $this->store[$this->jwksCid()] = (object) [
      'data' => $jwks,
      'created' => $this->now,
    ];
  }

  /**
   * Records a last-forced-refresh timestamp for the configured issuer.
   */
  private function primeForcedRefresh(int $timestamp): void {
    $this->store[$this->forcedCid()] = (object) [
      'data' => $timestamp,
      'created' => $timestamp,
    ];
  }

  /**
   * Signs a compact participant token with the requested kid.
   */
  private function token(string $kid, ?\OpenSSLAsymmetricKey $key = NULL): string {
    return JWT::encode([
      'iss' => self::ISSUER,
      'aud' => self::AUDIENCE,
      'sub' => 'synthetic-person',
      'exp' => $this->now + 600,
    ], $key ?? $this->currentKey['key'], 'RS256', $kid);
  }

  /**
   * Builds an RSA key and the JWKS document that publishes it.
   *
   * @return array{key: \OpenSSLAsymmetricKey, kid: string, jwks: array<string, mixed>}
   *   Signing material.
   */
  private function generateKey(string $kid): array {
    $key = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $this->assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    $this->assertIsArray($details);
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    return [
      'key' => $key,
      'kid' => $kid,
      'jwks' => [
        'keys' => [
          [
            'kty' => 'RSA',
            'kid' => $kid,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $encode($details['rsa']['n']),
            'e' => $encode($details['rsa']['e']),
          ],
        ],
      ],
    ];
  }

  /**
   * Cache id used by the validator for this issuer's JWKS.
   */
  private function jwksCid(): string {
    return 'agency_host:jwks:' . hash('sha256', self::ISSUER);
  }

  /**
   * Cache id used by the validator for this issuer's last forced refresh.
   */
  private function forcedCid(): string {
    return 'agency_host:jwks:forced:' . hash('sha256', self::ISSUER);
  }

  /**
   * Discovery document that points JWKS at the same issuer.
   */
  private function discoveryResponse(): Response {
    return new Response(200, [], json_encode([
      'jwks_uri' => self::ISSUER . '/keys',
    ]));
  }

  /**
   * JWKS HTTP body.
   *
   * @param array<string, mixed> $jwks
   *   The JWKS document.
   */
  private function jwksResponse(array $jwks): Response {
    return new Response(200, [], json_encode($jwks));
  }

}
