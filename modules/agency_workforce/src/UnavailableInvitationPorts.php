<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\Core\Database\Connection;

/**
 * Default ports deny without creating business records or widening authority.
 */
final class UnavailableInvitationPorts implements InvitationStoreInterface, TransactionalStaffProvisionerInterface, InvitationPolicyInterface, InvitationWriterBoundaryInterface {

  public function __construct(private readonly Connection $database) {}

  /**
   * {@inheritdoc}
   */
  public function connection(): Connection {
    return $this->database;
  }

  /**
   * {@inheritdoc}
   */
  public function collection(): string {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function lookupDigest(string $digest, bool $lock = FALSE): ?InvitationRecord {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function insertDigest(InvitationRecord $record): void {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function consume(InvitationRecord $record): int {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function accountExists(string $normalizedContact): bool {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function provision(string $normalizedContact, string $classification, string $language, string $preferredName): string {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function verifyBinding(string $binding, string $normalizedContact, string $classification, string $language): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function scope(): string {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function assertIssuerAllowed(): void {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function duration(?int $requested): int {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function language(?string $storedOrRequested): string {
    throw new InvitationRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function canReconcile(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function acquire(Connection $connection, string $trustedScope): void {
    throw new InvitationRefusal('unavailable');
  }

}
