<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Injects a failure after a real SQL revision restore in the synthetic target.
 */
final class InterruptibleStorage extends SqlContentEntityStorage {

  /**
   * Synthetic fault controls supplied by the test host.
   */
  protected ?StateInterface $interruptState = NULL;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $storage = parent::createInstance($container, $entity_type);
    $storage->interruptState = $container->get('state');
    return $storage;
  }

  /**
   * {@inheritdoc}
   */
  public function restore(EntityInterface $entity): void {
    parent::restore($entity);
    if ($entity instanceof ContentEntityInterface
      && (int) $this->interruptState?->get('agency_migrate_test.interrupt_revision', 0) === (int) $entity->getRevisionId()) {
      throw new \RuntimeException('Synthetic revision interruption.');
    }
  }

}
