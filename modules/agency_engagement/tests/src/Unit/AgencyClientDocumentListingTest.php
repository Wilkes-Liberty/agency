<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\ClientDocumentListing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Deliverable and invoice listings omit pay rails and foreign records.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyClientDocumentListingTest extends TestCase {

  /**
   * Site-timezone day supplied by the host adapter.
   */
  private const TODAY = '2026-11-21';

  /**
   * Authorized empty is OK with no items.
   */
  public function testAuthorizedEmptyIsOk(): void {
    $listing = ClientDocumentListing::invoices($this->okSubject(), []);
    $this->assertSame(ClientDocumentListing::STATUS_OK, $listing['status']);
    $this->assertSame('account-1', $listing['account']);
    $this->assertSame([], $listing['items']);
  }

  /**
   * A missing visitor binding is not_linked with no items.
   */
  public function testNotLinkedIsEmpty(): void {
    $listing = ClientDocumentListing::deliverables(
      $this->okSubject([
        'status' => ClientDocumentListing::STATUS_NOT_LINKED,
        'account' => NULL,
      ]),
      [$this->record()],
    );
    $this->assertSame(
      ClientDocumentListing::STATUS_NOT_LINKED,
      $listing['status'],
    );
    $this->assertNull($listing['account']);
    $this->assertSame([], $listing['items']);
  }

  /**
   * A bound non-client is not_client with no items.
   */
  public function testNotClientIsEmpty(): void {
    $listing = ClientDocumentListing::deliverables(
      $this->okSubject([
        'status' => ClientDocumentListing::STATUS_NOT_CLIENT,
        'account' => NULL,
      ]),
      [$this->record()],
    );
    $this->assertSame(
      ClientDocumentListing::STATUS_NOT_CLIENT,
      $listing['status'],
    );
    $this->assertSame([], $listing['items']);
  }

  /**
   * Permission failure is not a listing body.
   */
  public function testUnauthorizedIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not_authorized');
    ClientDocumentListing::deliverables(
      $this->okSubject(['authorized' => FALSE]),
      [$this->record()],
    );
  }

  /**
   * Flood is unavailable and advertises no items.
   */
  public function testFloodIsUnavailable(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('unavailable');
    ClientDocumentListing::invoices(
      $this->okSubject(['flood' => TRUE]),
      [$this->record(['kind' => ClientDocumentListing::INVOICE_KIND])],
    );
  }

  /**
   * Own published invoice is listed; deliverable kind is not.
   */
  public function testInvoiceKindOnlyOnInvoiceListing(): void {
    $records = [
      $this->record([
        'id' => 'inv-1',
        'kind' => ClientDocumentListing::INVOICE_KIND,
      ]),
      $this->record(['id' => 'del-1', 'kind' => 'deliverable']),
    ];
    $invoices = ClientDocumentListing::invoices($this->okSubject(), $records);
    $this->assertCount(1, $invoices['items']);
    $this->assertSame('inv-1', $invoices['items'][0]['id']);
    $this->assertSame(
      ClientDocumentListing::INVOICE_KEYS,
      array_keys($invoices['items'][0]),
    );
    $deliverables = ClientDocumentListing::deliverables(
      $this->okSubject(),
      $records,
    );
    $this->assertCount(2, $deliverables['items']);
  }

  /**
   * Unpublished, expired and unreachable records are omitted.
   */
  public function testUnpublishedExpiredAndUnreachableAreOmitted(): void {
    $records = [
      $this->record(['id' => 'open-1']),
      $this->record(['id' => 'draft-1', 'published' => FALSE]),
      $this->record(['id' => 'old-1', 'expires' => '2026-11-20']),
      $this->record(['id' => 'foreign-1', 'reachable' => FALSE]),
    ];
    $listing = ClientDocumentListing::deliverables(
      $this->okSubject(),
      $records,
    );
    $this->assertSame(['open-1'], array_column($listing['items'], 'id'));
  }

  /**
   * Expiry uses the caller-supplied site day, not UTC.
   */
  public function testExpiryUsesCallerToday(): void {
    $record = $this->record(['id' => 'edge-1', 'expires' => '2026-11-21']);
    $kept = ClientDocumentListing::deliverables($this->okSubject(), [$record]);
    $this->assertCount(1, $kept['items']);
    $dropped = ClientDocumentListing::deliverables(
      $this->okSubject(['today' => '2026-11-22']),
      [$record],
    );
    $this->assertSame([], $dropped['items']);
  }

  /**
   * Pay, ledger and download keys stay off both item contracts.
   */
  public function testPayKeysAreAbsent(): void {
    $record = $this->record([
      'kind' => ClientDocumentListing::INVOICE_KIND,
      'payUrl' => '/pay',
      'amount' => 9000,
      'balance' => 12,
      'downloadUrl' => 'https://files.example/a',
    ]);
    $invoice = ClientDocumentListing::invoices($this->okSubject(), [$record]);
    $deliverable = ClientDocumentListing::deliverables(
      $this->okSubject(),
      [$record],
    );
    foreach ([$invoice['items'][0], $deliverable['items'][0]] as $item) {
      foreach (ClientDocumentListing::PAY_KEYS as $key) {
        $this->assertArrayNotHasKey($key, $item);
      }
    }
    $this->assertSame(
      ClientDocumentListing::DELIVERABLE_KEYS,
      array_keys($deliverable['items'][0]),
    );
  }

  /**
   * Authorized subject defaults.
   *
   * @param array<string, mixed> $overrides
   *   Fields that differ from a fresh portal client.
   *
   * @return array<string, mixed>
   *   Subject facts.
   */
  private function okSubject(array $overrides = []): array {
    return $overrides + [
      'authorized' => TRUE,
      'flood' => FALSE,
      'status' => ClientDocumentListing::STATUS_OK,
      'account' => 'account-1',
      'today' => self::TODAY,
    ];
  }

  /**
   * One reachable published document.
   *
   * @param array<string, mixed> $overrides
   *   Fields that differ from a listed deliverable.
   *
   * @return array<string, mixed>
   *   Caller-supplied record.
   */
  private function record(array $overrides = []): array {
    return $overrides + [
      'id' => 'doc-1',
      'kind' => 'deliverable',
      'filename' => 'document.pdf',
      'sizeBytes' => 12,
      'createdAt' => '2026-11-01T00:00:00+00:00',
      'fileUuid' => 'file-1',
      'published' => TRUE,
      'reachable' => TRUE,
    ];
  }

}
