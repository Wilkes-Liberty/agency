<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Read;

use Drupal\agency_workforce\Exception\WorkforceProfileReadUnavailable;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;

/**
 * Authorizes and audits a minimized workforce own-profile read.
 */
final class WorkforceProfileReader {

  private const PERSON_REFUSED = ['person.profile_refused', NULL];

  public function __construct(
    private readonly WorkforceReadAuthorityInterface $authority,
    private readonly WorkforceProfileSourceInterface $source,
    private readonly AuditChainLoggerInterface $audit,
    private readonly Connection $database,
  ) {}

  /**
   * Returns an authorized display name or the generic identity refusal.
   *
   * The optional requested subject is a selector checked against the verified
   * principal. It cannot grant access. Call outside a database transaction so
   * the signed audit append commits before the result can be disclosed.
   *
   * @return array{outcome: 'read', payload: array{display_name: string}}|array{outcome: 'refused', reason: 'not_found'}
   *   The projected profile or the uniform refusal.
   */
  public function read(
    #[\SensitiveParameter] string $personUuid,
    #[\SensitiveParameter] ?string $requestedSubject = NULL,
  ): array {
    $this->requireIndependentAudit();
    try {
      [$operation, $displayName] = $this->project($personUuid, $requestedSubject);
    }
    catch (\Throwable) {
      // Provider exceptions may contain private claims or profile values.
      [$operation, $displayName] = self::PERSON_REFUSED;
    }
    if ($displayName === NULL) {
      $this->appendAudit($operation, ['reason' => 'not_found']);
      return ['outcome' => 'refused', 'reason' => 'not_found'];
    }
    $this->appendAudit($operation);
    return ['outcome' => 'read', 'payload' => ['display_name' => $displayName]];
  }

  /**
   * Resolves and checks a profile projection before recording its outcome.
   *
   * @return array{string, string|null}
   *   The audit operation and the display name when disclosure is authorized.
   */
  private function project(string $personUuid, ?string $requestedSubject): array {
    $authority = $this->authority->current();
    if (!$authority?->allowsOwnProfile()
      || ($requestedSubject !== NULL && $requestedSubject !== $authority->subject)
      || strlen($personUuid) !== 36
      || !Uuid::isValid($personUuid)) {
      return self::PERSON_REFUSED;
    }

    $binding = $this->source->bindingFor($personUuid);
    if ($binding === NULL || $binding->personUuid !== $personUuid) {
      return self::PERSON_REFUSED;
    }
    if ($binding->tenant !== $authority->tenant) {
      return ['paladin_agent.denied', NULL];
    }

    // The grant or identity may have changed during binding resolution.
    if (!$authority->allowsBinding($binding) || !$this->authority->current()?->allowsBinding($binding)) {
      return self::PERSON_REFUSED;
    }

    $displayName = $this->source->readDisplayName($binding);
    if ($displayName === NULL || !$this->authority->current()?->allowsBinding($binding)) {
      return self::PERSON_REFUSED;
    }
    return ['person.profile_listed', $displayName];
  }

  /**
   * Commits required signed evidence without disclosing backend diagnostics.
   */
  private function appendAudit(string $operation, array $metadata = []): void {
    $this->requireIndependentAudit();
    try {
      $this->audit->logKeyed('agency_workforce', $operation, $metadata);
    }
    catch (\Throwable) {
      throw new WorkforceProfileReadUnavailable();
    }
  }

  /**
   * Prevents an ambient transaction from rolling back required evidence.
   */
  private function requireIndependentAudit(): void {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Workforce profile reads require an independent audit append.');
    }
  }

}
