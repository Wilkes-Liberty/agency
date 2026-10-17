<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Delivery\DeliveryConfiguration;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rejects partial runtime custody and proves retained-key decryption.
 *
 * Failure modes: inherited settings survive absence; malformed JSON/retention
 * enables custody; a malformed retained key is accepted; rotation loses the
 * previous key; an environment load prints key material.
 *
 * @group agency_host
 */
#[Group('agency_host')]
final class DeliveryEnvironmentTest extends UnitTestCase {

  private const NAMES = ['MODE', 'KEY_ID', 'KEYS_JSON', 'RETENTION_SECONDS'];

  /**
   * Every incomplete or malformed configuration leaves delivery disabled.
   */
  public function testIncompleteEnvironmentCannotEnableCustody(): void {
    $valid = $this->fixture();
    $invalid = [[]];
    foreach (self::NAMES as $name) {
      $invalid[] = array_diff_key($valid, [$name => TRUE]);
    }
    foreach (['0', '-1', '1.5', ' 60', '1e3', '999999999999999999999999'] as $value) {
      $invalid[] = array_replace($valid, ['RETENTION_SECONDS' => $value]);
    }
    foreach ([
      '{', 'null', '[]', '{"current":5}', '{"current":"bad"}',
      '{"current":"' . base64_encode(str_repeat('k', 32)) . '","old":"bad"}',
    ] as $value) {
      $invalid[] = array_replace($valid, ['KEYS_JSON' => $value]);
    }
    $invalid[] = array_replace($valid, ['MODE' => 'SMTP']);
    $invalid[] = array_replace($valid, ['KEY_ID' => 'missing']);
    foreach ($invalid as $environment) {
      $settings = $this->load($environment);
      $this->assertSame([], $settings['agency_host_invitation_delivery']);
      new Settings($settings);
      try {
        (new DeliveryConfiguration())->read();
        $this->fail('Invalid runtime settings enabled custody.');
      }
      catch (Refusal $error) {
        $this->assertSame('delivery_disabled', $error->getMessage());
      }
    }
  }

  /**
   * Rotation keeps old ciphertext readable without changing its owning context.
   */
  public function testCaptureAndKeyRotationRoundTrip(): void {
    $environment = $this->fixture();
    new Settings($this->load($environment));
    $delivery = new DeliveryConfiguration();
    $cipher = $delivery->encrypt(['message' => 'Synthetic invitation'], 'synthetic-context');
    $keys = json_decode($environment['KEYS_JSON'], TRUE);
    $keys['next'] = base64_encode(str_repeat('n', 32));
    $environment['KEY_ID'] = 'next';
    $environment['KEYS_JSON'] = json_encode($keys);
    new Settings($this->load($environment));
    $this->assertSame('capture', $delivery->read()['mode']);
    $this->assertSame(60, $delivery->read()['payload_retention_seconds']);
    $this->assertSame(['message' => 'Synthetic invitation'], $delivery->decrypt($cipher, 'synthetic-context'));
    $this->expectException(Refusal::class);
    $delivery->decrypt($cipher, 'another-context');
  }

  /**
   * Only explicit smtp selects the existing real-delivery adapter.
   */
  public function testSmtpRequiresExplicitMode(): void {
    new Settings($this->load(array_replace($this->fixture(), ['MODE' => 'smtp'])));
    $this->assertSame('smtp', (new DeliveryConfiguration())->read()['mode']);
  }

  /**
   * Uses known synthetic bytes, never an application credential.
   */
  private function fixture(): array {
    return [
      'MODE' => 'capture',
      'KEY_ID' => 'current',
      'KEYS_JSON' => json_encode(['current' => base64_encode(str_repeat('k', 32))]),
      'RETENTION_SECONDS' => '60',
    ];
  }

  /**
   * Loads the actual early-bootstrap include and restores the process env.
   */
  private function load(array $environment): array {
    $previous = [];
    $settings = ['agency_host_invitation_delivery' => ['mode' => 'smtp']];
    foreach (self::NAMES as $name) {
      $key = 'DRUPAL_AGENCY_DELIVERY_' . $name;
      $previous[$key] = getenv($key);
      putenv(isset($environment[$name]) ? $key . '=' . $environment[$name] : $key);
    }
    ob_start();
    try {
      require dirname(__DIR__, 2) . '/fixtures/settings.invitation-delivery.php';
    }
    finally {
      $output = ob_get_clean();
      foreach ($previous as $key => $value) {
        putenv($value === FALSE ? $key : $key . '=' . $value);
      }
    }
    $this->assertSame('', $output, 'Runtime settings must not print key material.');
    return $settings;
  }

}
