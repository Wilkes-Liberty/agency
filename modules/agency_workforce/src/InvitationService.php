<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Portable anonymous staff onboarding with local transaction participants.
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
    private readonly InvitationJournal $journal,
  ) {}

  /**
   * Issues a transient bearer only after store, receipt and evidence commit.
   */
  public function issue(string $contact, string $classification, ?string $language = NULL, ?int $duration = NULL): array {
    $this->assertRootBoundary();
    $this->policy->assertIssuerAllowed();
    [$scope, $collection] = $this->context(TRUE);
    $contact = strtolower(trim($contact));
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL)
      || !in_array($classification, ['employee', 'contractor'], TRUE)) {
      throw new InvitationRefusal('invalid_invitation');
    }
    $language = $this->configuredLanguage($language);
    $duration = $this->policy->duration($duration);
    if ($duration <= 0 || $duration > PHP_INT_MAX - $this->time->getCurrentTime()) {
      throw new InvitationRefusal('configuration');
    }
    $bearer = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $digest = $this->digest($bearer);
    $id = $this->uuid->generate();
    return $this->perform($id, function () use ($id, $scope, $collection, $contact, $classification, $language, $duration, $bearer, $digest): array {
      $this->boundary->acquire($this->database, $scope);
      $this->lockContactAndInvitation($scope, $collection, $contact, $digest);
      if ($this->provisioner->accountExists($contact)) {
        throw new InvitationRefusal('collision');
      }
      $record = new InvitationRecord($collection, $digest, $contact, $classification, $language, $this->time->getCurrentTime() + $duration);
      $this->store->insertDigest($record);
      $persisted = $this->store->lookupDigest($digest, TRUE);
      if ($persisted === NULL || $persisted->collection !== $collection
        || !hash_equals($digest, $persisted->digest)
        || $persisted->contact !== $contact
        || $persisted->classification !== $classification
        || $persisted->language !== $language
        || $persisted->expires !== $record->expires) {
        throw new InvitationRefusal('storage_conflict');
      }
      $this->journal->record($id, $scope, $record, 'invitation_issued');
      $this->journal->recordStage($id, 'invite.issued', $record);
      return ['status' => 'completed', 'receipt_id' => $id, 'bearer' => $bearer, 'expires' => $record->expires];
    });
  }

  /**
   * Returns a bearer-protected compatibility view without consuming or binding.
   */
  public function inspect(string $bearer): array {
    [, $collection] = $this->context(FALSE);
    try {
      $digest = $this->digest($bearer);
      [$record, $language] = $this->validateRecord($this->store->lookupDigest($digest), $collection, $digest);
      return [
        'valid' => TRUE,
        'contact' => $record->contact,
        'classification' => $record->classification,
        'language' => $language,
        'expires' => $record->expires,
      ];
    }
    catch (\Throwable) {
      return ['valid' => FALSE];
    }
  }

  /**
   * Commits account, profile, conditional consumption and required evidence.
   */
  public function accept(string $bearer, string $preferredName = ''): array {
    $this->assertAnonymousPost();
    $this->assertRootBoundary();
    [$scope, $collection] = $this->context(TRUE);
    $digest = $this->digest($bearer);
    if (strlen($preferredName) > 255) {
      throw new InvitationRefusal('invalid_invitation');
    }
    $id = $this->uuid->generate();
    return $this->perform($id, function () use ($scope, $collection, $digest, $preferredName, $id): array {
      $this->boundary->acquire($this->database, $scope);
      // This read discovers only the contact mutex; it never authorizes work.
      [$initial] = $this->validateRecord($this->store->lookupDigest($digest), $collection, $digest);
      $this->lockContactAndInvitation($scope, $collection, $initial->contact, $digest);
      [$record, $language] = $this->validateRecord($this->store->lookupDigest($digest, TRUE), $collection, $digest);
      if ($record->contact !== $initial->contact || $record->version !== $initial->version) {
        throw new InvitationRefusal('invalid_invitation');
      }
      if ($this->provisioner->accountExists($record->contact)) {
        throw new InvitationRefusal('collision');
      }
      $this->journal->recordStage($id, 'invite.claimed', $record);
      $binding = $this->provisioner->provision($record->contact, $record->classification, $language, $preferredName);
      if (!$this->validBinding($binding)
        || !$this->provisioner->accountExists($record->contact)
        || !$this->provisioner->verifyBinding($binding, $record->contact, $record->classification, $language)) {
        throw new InvitationRefusal('storage_conflict');
      }
      $this->journal->recordStage($this->uuid->generate(), 'invite.provisioned', $record);
      if ($this->store->consume($record) !== 1
        || $this->store->lookupDigest($digest, TRUE) !== NULL) {
        throw new InvitationRefusal('storage_conflict');
      }
      $this->journal->recordStage($this->uuid->generate(), 'invite.consumed', $record);
      $this->journal->record($id, $scope, $record, 'invitation_accepted', $binding);
      return ['status' => 'completed', 'receipt_id' => $id, 'binding' => $binding];
    });
  }

  /**
   * Accepts an invitation with an opaque application refusal.
   *
   * Callers choosing this boundary delegate invitation refusal evidence to
   * Agency. The native accept() method leaves that evidence with its host.
   */
  public function acceptForApplication(string $bearer, string $preferredName = ''): array {
    try {
      return $this->accept($bearer, $preferredName);
    }
    catch (InvitationRefusal) {
      // A typed refusal either preceded business work or followed confirmed
      // rollback. An ambient transaction cannot durably record this intent.
      if ($this->database->inTransaction()) {
        return ['status' => 'unresolved'];
      }
      try {
        $this->journal->recordRefusal($this->uuid->generate());
      }
      catch (\Throwable) {
        return ['status' => 'unresolved'];
      }
      return ['outcome' => 'refused', 'reason' => 'not_found'];
    }
  }

  /**
   * Reconciles a known operation with bearer or authorized operator proof.
   */
  public function reconcile(string $receiptId, ?string $bearer = NULL): array {
    $this->assertRootBoundary();
    [$scope, $collection] = $this->context(FALSE);
    $operator = $this->policy->canReconcile();
    $digest = $this->recoveryProof($operator, $bearer);
    if (!Uuid::isValid($receiptId)) {
      throw new InvitationRefusal('forbidden');
    }
    try {
      $row = $this->journal->byId($receiptId, $scope, $collection);
    }
    catch (\Throwable) {
      return ['status' => 'unresolved'];
    }
    return $this->confirmed($row, $operator, $digest);
  }

  /**
   * Recovers a lost response using its original bearer, without reprovisioning.
   */
  public function recover(string $bearer): array {
    $this->assertRootBoundary();
    [$scope, $collection] = $this->context(FALSE);
    $operator = $this->policy->canReconcile();
    if (!$operator) {
      $this->assertAnonymousPost();
    }
    $digest = $this->digest($bearer);
    try {
      $row = $this->journal->byDigest($digest, $scope, $collection);
    }
    catch (\Throwable) {
      return ['status' => 'unresolved'];
    }
    return $this->confirmed($row, $operator, $digest);
  }

  /**
   * Authorized operator recovery uses an existing digest without hashing again.
   */
  public function recoverByDigest(string $digest): array {
    $this->assertRootBoundary();
    [$scope, $collection] = $this->context(FALSE);
    if (!$this->policy->canReconcile() || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
      throw new InvitationRefusal('forbidden');
    }
    try {
      $row = $this->journal->byDigest($digest, $scope, $collection);
    }
    catch (\Throwable) {
      return ['status' => 'unresolved'];
    }
    return $this->confirmed($row, TRUE, $digest);
  }

  /**
   * Runs one root transaction and never reports savepoint/uncertain success.
   */
  private function perform(string $id, callable $work): array {
    $transaction = $this->database->startTransaction();
    $committing = FALSE;
    try {
      $result = $work();
      $committing = TRUE;
      $this->committer->commit($transaction);
      if ($this->database->inTransaction()) {
        throw new \LogicException('Root commit was not completed.');
      }
      unset($transaction);
      return $result;
    }
    catch (\Throwable $exception) {
      $rollbackFailed = FALSE;
      try {
        $transaction?->rollBack();
      }
      catch (\Throwable) {
        $rollbackFailed = TRUE;
      }
      finally {
        unset($transaction);
      }
      if ($committing || $rollbackFailed || $this->database->inTransaction()) {
        return ['status' => 'uncertain', 'receipt_id' => $id];
      }
      throw $exception instanceof InvitationRefusal ? $exception : new InvitationRefusal('failed');
    }
  }

  /**
   * Checks trusted configuration and exact transaction participants.
   *
   * @return array{string, string}
   *   Trusted scope and owning collection.
   */
  private function context(bool $provisioning): array {
    try {
      \agency_require_display_name();
    }
    catch (\LogicException) {
      throw new InvitationRefusal('configuration');
    }
    $scope = $this->policy->scope();
    $collection = $this->store->collection();
    if (!preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $scope)
      || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $collection)) {
      throw new InvitationRefusal('configuration');
    }
    if ($this->store->connection() !== $this->database
      || ($provisioning && $this->provisioner->connection() !== $this->database)) {
      throw new InvitationRefusal('transaction_boundary');
    }
    return [$scope, $collection];
  }

  /**
   * Validates fresh storage before deriving a contact lock or provisioning.
   *
   * @return array{\Drupal\agency_workforce\InvitationRecord, string}
   *   Validated source snapshot and its configured language.
   */
  private function validateRecord(?InvitationRecord $record, string $collection, string $digest): array {
    if ($record === NULL || $record->collection !== $collection || !hash_equals($digest, $record->digest)
      || $record->expires <= $this->time->getCurrentTime()) {
      throw new InvitationRefusal('invalid_invitation');
    }
    return [$record, $this->configuredLanguage($record->language)];
  }

  /**
   * Language validation remains with configured host policy.
   */
  private function configuredLanguage(?string $language): string {
    $resolved = $this->policy->language($language);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D', $resolved)) {
      throw new InvitationRefusal('configuration');
    }
    return $resolved;
  }

  /**
   * Durable SQL exclusion in fixed authority, contact, invitation order.
   */
  private function lockContactAndInvitation(string $scope, string $collection, string $contact, string $digest): void {
    foreach (['c:' . $scope . ':' . hash('sha256', $contact), 'i:' . $scope . ':' . $collection . ':' . $digest] as $key) {
      $this->database->upsert('agency_workforce_mutex')->key('lock_key')->fields(['lock_key'])->values([$key])->execute();
      $query = $this->database->select('agency_workforce_mutex', 'm')->fields('m')->condition('lock_key', $key);
      $query->forUpdate();
      if (!$query->execute()->fetchAssoc()) {
        throw new InvitationRefusal('storage_conflict');
      }
    }
  }

  /**
   * Refuses nested invocation before any business write.
   */
  private function assertRootBoundary(): void {
    if ($this->database->inTransaction()) {
      throw new InvitationRefusal('transaction_boundary');
    }
  }

  /**
   * Checks server-derived account and request context.
   */
  private function assertAnonymousPost(): void {
    if (!$this->currentUser->isAnonymous() || $this->requests->getCurrentRequest()?->getMethod() !== 'POST') {
      throw new InvitationRefusal('forbidden');
    }
  }

  /**
   * Hashes a valid raw bearer once; an existing digest is not a bearer.
   */
  private function digest(string $bearer): string {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $bearer)) {
      throw new InvitationRefusal('invalid_invitation');
    }
    return hash('sha256', $bearer);
  }

  /**
   * Restricts opaque local bindings to the declared ASCII storage bound.
   */
  private function validBinding(string $binding): bool {
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9:_.-]{0,254}$/D', $binding);
  }

  /**
   * Requires anonymous bearer proof unless host operator authority applies.
   */
  private function recoveryProof(bool $operator, ?string $bearer): ?string {
    if (!$operator) {
      $this->assertAnonymousPost();
      if ($bearer === NULL) {
        throw new InvitationRefusal('forbidden');
      }
    }
    return $bearer === NULL ? NULL : $this->digest($bearer);
  }

  /**
   * Discloses only minimal committed state under proof and original expiry.
   *
   * @param array<string, mixed>|null $row
   *   A committed operation receipt.
   * @param bool $operator
   *   Whether trusted host operator authority permits reconciliation.
   * @param string|null $digest
   *   Bearer-derived proof, or NULL for authorized operator recovery.
   */
  private function confirmed(?array $row, bool $operator, ?string $digest): array {
    if ($row === NULL) {
      return ['status' => 'unresolved'];
    }
    if (!$operator && ($digest === NULL || !hash_equals($row['digest'], $digest)
      || (int) $row['expires'] <= $this->time->getCurrentTime())) {
      throw new InvitationRefusal('forbidden');
    }
    if (!in_array($row['operation'], ['invitation_issued', 'invitation_accepted'], TRUE)
      || ($row['binding'] !== NULL && !$this->validBinding($row['binding']))) {
      return ['status' => 'unresolved'];
    }
    $result = ['status' => 'completed', 'receipt_id' => $row['id'], 'operation' => $row['operation']];
    if ($row['binding'] !== NULL) {
      $result['binding'] = $row['binding'];
    }
    return $result;
  }

}
