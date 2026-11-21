<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\CommercialAccountProjection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the commercial-account allowlist without a Profile entity.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyCommercialAccountProjectionTest extends TestCase {

  /**
   * Staff-only and CRM keys that must never appear on the projection.
   */
  private const FORBIDDEN = [
    'taxId',
    'billingEmail',
    'billingPhone',
    'billingAddress',
    'billingContact',
    'paymentTerms',
    'poNumber',
    'mail',
    'opportunity',
    'amount',
    'balance',
    'user',
    'profile',
    'field_tax_id',
    'field_billing_address',
  ];

  /**
   * The empty projection is exactly the ratified keys.
   */
  public function testEmptyProjectionIsExactlyTheAllowlist(): void {
    $empty = CommercialAccountProjection::empty();
    $this->assertSame(CommercialAccountProjection::KEYS, array_keys($empty));
    $this->assertSame([
      'accountNumber' => NULL,
      'billingLegalName' => NULL,
      'paymentMethodLabel' => NULL,
    ], $empty);
  }

  /**
   * Tax, address, financial, opportunity and entity dumps stay off the keys.
   */
  public function testForbiddenKeysAreAbsent(): void {
    foreach (self::FORBIDDEN as $key) {
      $this->assertNotContains($key, CommercialAccountProjection::KEYS);
      $this->assertArrayNotHasKey($key, CommercialAccountProjection::empty());
    }
  }

  /**
   * A missing record fails closed to the empty allowlist.
   */
  public function testMissingRecordIsEmpty(): void {
    $this->assertSame(
      CommercialAccountProjection::empty(),
      CommercialAccountProjection::fromRecord(NULL),
    );
  }

  /**
   * Unknown payment methods do not pass through as labels.
   */
  public function testUnknownPaymentMethodIsNull(): void {
    $projection = CommercialAccountProjection::fromRecord([
      'account_number' => 'A-100',
      'billing_legal_name' => 'Example Co',
      'payment_method' => 'cash',
      'payment_labels' => ['cash' => 'Cash'],
    ]);
    $this->assertSame('A-100', $projection['accountNumber']);
    $this->assertSame('Example Co', $projection['billingLegalName']);
    $this->assertNull($projection['paymentMethodLabel']);
  }

  /**
   * Forbidden keys on the source record are unread.
   */
  public function testForbiddenRecordKeysDoNotPassThrough(): void {
    $projection = CommercialAccountProjection::fromRecord([
      'account_number' => 'A-100',
      'taxId' => '12-345',
      'amount' => 9000,
      'opportunity' => 'opp-1',
      'payment_method' => 'invoice',
      'payment_labels' => ['invoice' => 'Invoice'],
    ]);
    $this->assertSame(
      CommercialAccountProjection::KEYS,
      array_keys($projection),
    );
    foreach (self::FORBIDDEN as $key) {
      $this->assertArrayNotHasKey($key, $projection);
    }
    $this->assertSame('Invoice', $projection['paymentMethodLabel']);
  }

}
