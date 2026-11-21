<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use PHPUnit\Framework\TestCase;

/**
 * Asserts public G2 bodies omit contact, token and dispatch payload keys.
 */
final class S3DenyAssertions {

  /**
   * JSON object keys that must never appear on a public G2 body.
   */
  public const FORBIDDEN_KEYS = [
    'token',
    'email',
    'phone',
    'sub',
    'filename',
    'jwt',
    'tax_id',
    'amount',
    'dispatch_key',
    'gateway_url',
    'api_token',
  ];

  /**
   * Asserts a JSON object has none of the payload keys.
   *
   * @param \PHPUnit\Framework\TestCase $test
   *   Calling test.
   * @param mixed $payload
   *   Public body or refusal outcome.
   */
  public static function assertPayloadAbsent(
    TestCase $test,
    mixed $payload,
  ): void {
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, TRUE);
    $test->assertIsArray($decoded);
    $keys = self::keys($decoded);
    foreach (self::FORBIDDEN_KEYS as $key) {
      $test->assertNotContains($key, $keys, $key);
      $test->assertArrayNotHasKey($key, $decoded, $key);
    }
  }

  /**
   * Collects JSON object keys recursively.
   *
   * @param array<mixed> $payload
   *   Decoded JSON object.
   *
   * @return list<string>
   *   Every object key in the tree.
   */
  private static function keys(array $payload): array {
    $found = [];
    foreach ($payload as $key => $value) {
      $found[] = (string) $key;
      if (is_array($value)) {
        $found = array_merge($found, self::keys($value));
      }
    }
    return $found;
  }

}
