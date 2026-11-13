<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Loads Agency-side rows only when operational authority is agency.
 *
 * Host adapters must consult this reader. Entity API loads are not gated.
 */
final class AuthorityGatedReader {

  public function __construct(
    private readonly AuthorityRegister $authority,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MigrateOutbox $outbox,
    private readonly UuidInterface $uuid,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Loads one Agency-side record after consulting operational authority.
   *
   * Deny-first: any state other than agency, including unregistered
   * domains, refuses before storage is opened. At legacy the
   * operator-visible reason is `legacy`, not `not_found`.
   */
  public function readAgency(
    string $domain,
    string $entity_type,
    int $id,
  ): ContentEntityInterface {
    $state = $this->authority->get($domain);
    if ($state !== 'agency') {
      $reason = $state ?? 'invalid_transition';
      $this->recordRefusal($domain, $entity_type, $id, $reason);
      throw new MigrateRefusal($reason);
    }
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
    }
    catch (PluginNotFoundException) {
      throw new MigrateRefusal('plugin_missing');
    }
    $storage->resetCache([$id]);
    $entity = $storage->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new MigrateRefusal('not_found');
    }
    return $entity;
  }

  /**
   * Records a minimized outbox intent so a refused load still leaves a trace.
   *
   * Follows the unmapped-marking stop: unique fingerprint, hashed actor,
   * operator-visible reason, no record payloads or personal data.
   */
  private function recordRefusal(
    string $domain,
    string $entity_type,
    int $id,
    string $reason,
  ): void {
    $fingerprint = hash('sha256', implode(':', [
      'agency_read_refused',
      $domain,
      $entity_type,
      (string) $id,
      $this->uuid->generate(),
    ]));
    $this->outbox->record('agency_read_refused', [
      'domain' => $domain,
      'entity_type' => $entity_type,
      'entity_id' => $id,
      'reason' => $reason,
      'actor_hash' => hash('sha256', 'uid:' . $this->currentUser->id()),
    ], $fingerprint);
  }

}
