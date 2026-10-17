<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\Identity\CleanOriginReceipt;
use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Failure modes: self-approval, forged target/GO, expiry, drift and ambiguous JSON.
 *
 * These isolate the external attestation boundary, not database qualification.
 */
#[CoversClass(CleanOriginReceipt::class)]
#[Group('agency_host')]
final class CleanOriginReceiptTest extends UnitTestCase {

  private const ISSUER = 'https://identity.example.test/realms/new-origin';

  /**
   * Only independently pinned, exact canonical bytes authenticate an attestation.
   */
  public function testIndependentApprovalBoundary(): void {
    $receipt = $this->receipt();
    $bytes = $this->encode($receipt);
    $approval = $this->approval($bytes);
    $verified = $this->verifier($approval)->verify($bytes, self::ISSUER, 'origin-v1', str_repeat('d', 64));
    $this->assertSame($receipt, $verified);
    foreach ([[], $approval + ['public_key' => 'unused']] as $index => $settings) {
      if ($index === 1) {
        $settings['receipt_sha256'] = str_repeat('0', 64);
      }
      $this->refuses($bytes, $settings);
    }
    $forged = $receipt;
    $forged['operator_sha256'] = str_repeat('f', 64);
    $this->refuses($this->encode($forged), $approval);
    // A receipt cannot carry its own pin, even if its new bytes were pinned.
    $forged['receipt_sha256'] = hash('sha256', $this->encode($forged));
    $bytes = $this->encode($forged);
    $this->refuses($bytes, $this->approval($bytes));
  }

  /**
   * A pin authenticates bytes; it does not override target, time or source checks.
   */
  public function testApprovedButWrongContextIsRefused(): void {
    foreach ([
      ['target_sha256', str_repeat('0', 64)],
      ['execution_go_sha256', str_repeat('0', 64)],
      ['issuer', self::ISSUER . '-other'],
      ['binding_version', 'other-version'],
      ['host_fingerprint', str_repeat('0', 64)],
      ['expires_at', 1000],
      ['created_at', 1100],
      ['contained_from', 999],
      ['realm_incarnation_sha256', ''],
      ['operator_sha256', NULL],
      ['format', 'unknown'],
    ] as [$key, $value]) {
      $receipt = $this->receipt();
      $receipt[$key] = $value;
      $bytes = $this->encode($receipt);
      $this->refuses($bytes, $this->approval($bytes));
    }
  }

  /**
   * Parser ambiguity, missing fields and unbounded inputs cannot cross the pin.
   */
  public function testMalformedPinnedBytesAreRefused(): void {
    $receipt = $this->receipt();
    $canonical = $this->encode($receipt);
    unset($receipt['creation_record_sha256']);
    foreach ([
      $canonical . "\n",
      '{"format":"unknown",' . substr($canonical, 1),
      $this->encode($receipt),
      'null',
      '[]',
      str_repeat('x', 16385),
    ] as $bytes) {
      $this->refuses($bytes, $this->approval($bytes));
    }
  }

  /**
   * Builds a synthetic attestation, not an actual origin or operational receipt.
   */
  private function receipt(): array {
    $receipt = [
      'format' => 'agency-clean-origin-v1',
      'target_sha256' => str_repeat('a', 64),
      'issuer' => self::ISSUER,
      'binding_version' => 'origin-v1',
      'realm_incarnation_sha256' => str_repeat('b', 64),
      'creation_record_sha256' => str_repeat('c', 64),
      'containment_record_sha256' => str_repeat('e', 64),
      'execution_go_sha256' => str_repeat('f', 64),
      'operator_sha256' => str_repeat('1', 64),
      'host_fingerprint' => str_repeat('d', 64),
      'ui_composition_sha256' => str_repeat('2', 64),
      'created_at' => 900,
      'contained_from' => 800,
      'expires_at' => 1200,
    ];
    ksort($receipt);
    return $receipt;
  }

  /**
   * The approval is independently managed runtime settings, never receipt input.
   */
  private function approval(string $bytes): array {
    return [
      'receipt_sha256' => hash('sha256', $bytes),
      'target_sha256' => str_repeat('a', 64),
      'execution_go_sha256' => str_repeat('f', 64),
    ];
  }

  /**
   * Uses the actual Settings boundary with a controlled clock.
   */
  private function verifier(array $approval): CleanOriginReceipt {
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn(1000);
    return new CleanOriginReceipt(new Settings(['agency_host_origin_approval' => $approval]), $clock);
  }

  /**
   * Encodes only the defined canonical scalar JSON representation.
   */
  private function encode(array $receipt): string {
    ksort($receipt);
    return json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Refusal diagnostics must not echo potentially sensitive receipt contents.
   */
  private function refuses(string $bytes, array $approval): void {
    try {
      $this->verifier($approval)->verify($bytes, self::ISSUER, 'origin-v1', str_repeat('d', 64));
      $this->fail('An untrusted or stale attestation was accepted.');
    }
    catch (IdentityDirectoryUnavailable $error) {
      $this->assertSame('Clean-origin attestation is unavailable.', $error->getMessage());
    }
  }

}
