<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Access;

use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Synthetic reader that consults operational authority before Agency loads.
 *
 * This is a proving double for host adapters, not a production reader.
 */
final class AuthorityGatedReader {

  public function __construct(
    private readonly AuthorityRegister $authority,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads an Agency-side synthetic row only at Agency authority.
   */
  public function readAgency(string $domain, int $id): ContentEntityInterface {
    $state = $this->authority->get($domain);
    if ($state !== 'agency') {
      throw new MigrateRefusal($state ?? 'invalid_transition');
    }
    $storage = $this->entityTypeManager->getStorage('agency_migrate_agency_item');
    $storage->resetCache([$id]);
    $entity = $storage->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new MigrateRefusal('invalid_transition');
    }
    return $entity;
  }

}
