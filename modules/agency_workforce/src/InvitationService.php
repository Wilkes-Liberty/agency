<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Staff invitation compatibility API under failure-first integration tests.
 */
final class InvitationService {

  public function __construct(
    private readonly Connection $database,
    private readonly InvitationStoreInterface $store,
    private readonly TransactionalStaffProvisionerInterface $provisioner,
    private readonly InvitationPolicyInterface $policy,
    private readonly InvitationWriterBoundaryInterface $boundary,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requests,
    private readonly UuidInterface $uuid,
    private readonly TransactionCommitterInterface $committer,
  ) {}

  public function issue(string $contact, string $classification, ?string $language = NULL, ?int $duration = NULL): array {
    throw new InvitationRefusal('not_implemented');
  }

  public function inspect(string $bearer): array {
    throw new InvitationRefusal('not_implemented');
  }

  public function accept(string $bearer, string $preferredName = ''): array {
    throw new InvitationRefusal('not_implemented');
  }

  public function reconcile(string $receiptId, ?string $bearer = NULL): array {
    throw new InvitationRefusal('not_implemented');
  }

  public function recover(string $bearer): array {
    throw new InvitationRefusal('not_implemented');
  }

  public function recoverByDigest(string $digest): array {
    throw new InvitationRefusal('not_implemented');
  }

}
