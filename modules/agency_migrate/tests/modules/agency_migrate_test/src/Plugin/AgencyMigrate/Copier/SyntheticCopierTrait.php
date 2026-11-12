<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Plugin\AgencyMigrate\Copier;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Shared synthetic access, markings and interrupt behavior.
 *
 * @property \Drupal\Core\State\StateInterface $state
 */
trait SyntheticCopierTrait {

  /**
   * {@inheritdoc}
   */
  public function propertyMarkings(): array {
    return [
      'title' => 'public',
      'payload' => 'internal',
      'formatted' => 'internal',
      'reviewers' => 'internal',
      'related_id' => 'internal',
      'access_class' => 'confidential',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function referencePropertyNames(): array {
    return ['related_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function accessDecision(string $principal, string $side, int $id): bool {
    $entity = $side === 'source' ? $this->loadSource($id) : $this->loadTarget($id);
    if (!$entity instanceof ContentEntityInterface) {
      return FALSE;
    }
    $class = (string) $entity->get('access_class')->value;
    if ($class === 'open') {
      return TRUE;
    }
    return $principal === 'owner-' . $id;
  }

  /**
   * {@inheritdoc}
   */
  public function historyManifest(ContentEntityInterface $entity): array {
    if ($this->state->get('agency_migrate_test.invalid_history_manifest')) {
      return [];
    }
    return parent::historyManifest($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function copyToTarget(ContentEntityInterface $source): ContentEntityInterface {
    $stop = $this->state->get('agency_migrate_test.interrupt_title');
    if (is_string($stop) && $stop !== '' && (string) $source->get('title')->value === $stop) {
      throw new \RuntimeException('synthetic interrupt');
    }
    $target = parent::copyToTarget($source);
    if ($this->state->get('agency_migrate_test.change_source_during_copy')) {
      $source->setNewRevision(TRUE);
      $source->isDefaultRevision(FALSE);
      $source->set('payload', 'In-process source mutation');
      $source->save();
    }
    return $target;
  }

}
