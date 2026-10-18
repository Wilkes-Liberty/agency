<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\agency_host\HostSettings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;

/**
 * Verifies an independently authenticated origin/containment attestation.
 *
 * A protected digest authenticates approved bytes, not historical truth. The
 * operator establishes creation provenance and continuous external containment.
 * This verifier never qualifies history, writes storage or grants access.
 */
final class CleanOriginReceipt {

  private const DIGESTS = [
    'target_sha256', 'realm_incarnation_sha256', 'creation_record_sha256',
    'containment_record_sha256', 'execution_go_sha256', 'operator_sha256',
    'host_fingerprint', 'ui_composition_sha256',
  ];

  public function __construct(
    private readonly Settings $settings,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Accepts canonical bytes only against independent deployment-owned pins.
   *
   * Context arguments must come from the host authority/history services, never
   * from the receipt or caller-supplied tool arguments. Approval settings must
   * be provisioned outside editable Drupal config after external authentication.
   */
  public function verify(string $bytes, string $issuer, string $bindingVersion, string $fingerprint): array {
    try {
      $approval = HostSettings::get($this->settings, 'origin_approval', []);
      if (strlen($bytes) > 16384 || !is_array($approval)) {
        throw new \UnexpectedValueException();
      }
      foreach (['receipt_sha256', 'target_sha256', 'execution_go_sha256'] as $key) {
        if (!$this->digest($approval[$key] ?? NULL)) {
          throw new \UnexpectedValueException();
        }
      }
      if (!hash_equals($approval['receipt_sha256'], hash('sha256', $bytes))) {
        throw new \UnexpectedValueException();
      }
      $receipt = json_decode($bytes, TRUE, 4, JSON_THROW_ON_ERROR);
      if (!is_array($receipt)) {
        throw new \UnexpectedValueException();
      }
      $keys = [...self::DIGESTS, 'format', 'issuer', 'binding_version', 'created_at', 'contained_from', 'expires_at'];
      sort($keys);
      ksort($receipt);
      if (array_keys($receipt) !== $keys
        || json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $bytes) {
        throw new \UnexpectedValueException();
      }
      foreach (self::DIGESTS as $key) {
        if (!$this->digest($receipt[$key])) {
          throw new \UnexpectedValueException();
        }
      }
      foreach (['created_at', 'contained_from', 'expires_at'] as $key) {
        if (!is_int($receipt[$key]) || $receipt[$key] <= 0) {
          throw new \UnexpectedValueException();
        }
      }
      $now = $this->time->getCurrentTime();
      if ($receipt['format'] !== 'agency-clean-origin-v1'
        || $receipt['target_sha256'] !== $approval['target_sha256']
        || $receipt['execution_go_sha256'] !== $approval['execution_go_sha256']
        || $receipt['issuer'] !== $issuer || $receipt['binding_version'] !== $bindingVersion
        || $receipt['host_fingerprint'] !== $fingerprint
        || $receipt['contained_from'] > $receipt['created_at']
        || $receipt['created_at'] > $now || $receipt['expires_at'] <= $now) {
        throw new \UnexpectedValueException();
      }
      return $receipt;
    }
    catch (\Throwable) {
      // Receipts and parse exceptions may contain private operational values.
      throw new IdentityDirectoryUnavailable('Clean-origin attestation is unavailable.');
    }
  }

  /**
   * Requires opaque SHA-256 references, never a record payload or credential.
   */
  private function digest(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
  }

}
