<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared synthetic host for agency_migrate kernel proofs.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class MigrateKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'key',
    'encrypt',
    'audit_chain',
    'agency',
    'agency_migrate',
    'agency_migrate_test',
  ];

  /**
   * Operator with migration and authority permissions, not uid 1.
   */
  protected User $operator;

  /**
   * Revision author used on synthetic source records.
   */
  protected User $author;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item']]);
    $this->installEntitySchema('user');
    $this->installEntitySchema('agency_migrate_legacy_item');
    $this->installEntitySchema('agency_migrate_agency_item');
    $this->installSchema('agency_migrate', [
      'agency_migrate_lineage',
      'agency_migrate_run',
      'agency_migrate_outbox',
      'agency_migrate_write_log',
      'agency_migrate_rollback_receipt',
      'agency_migrate_rollback_record',
      'agency_migrate_authority_event',
      'agency_migrate_authority_state',
    ]);
    $this->installConfig(['system', 'user', 'audit_chain', 'agency_migrate']);
    User::create(['name' => 'reserved', 'status' => 1])->save();
    Role::create([
      'id' => 'migrate_operator',
      'label' => 'Migrate operator',
      'permissions' => [
        'run agency migrate',
        'administer agency migrate authority',
      ],
    ])->save();
    $this->operator = User::create([
      'name' => 'example-operator',
      'status' => 1,
      'roles' => ['migrate_operator'],
    ]);
    $this->operator->save();
    $this->author = User::create([
      'name' => 'example-author',
      'status' => 1,
    ]);
    $this->author->save();
    $this->container->get('current_user')->setAccount($this->operator);
  }

  /**
   * Creates synthetic source records from the fixture file.
   *
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   *   Saved source entities.
   */
  protected function seedSources(): array {
    $path = dirname(__DIR__, 3) . '/fixtures/migrate/records.json';
    $data = json_decode(file_get_contents($path), TRUE, 16, JSON_THROW_ON_ERROR);
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_migrate_legacy_item');
    $entities = [];
    foreach ($data['records'] as $row) {
      $entity = $storage->create([
        'title' => $row['title'],
        'payload' => $row['payload'],
        'related_id' => $row['related_id'],
        'access_class' => $row['access_class'],
        'revision_log_message' => $row['revision_log'],
        'revision_user' => $this->author->id(),
      ]);
      $entity->save();
      if (!$entity instanceof ContentEntityInterface) {
        throw new \RuntimeException('Expected a content entity.');
      }
      $entities[] = $entity;
    }
    return $entities;
  }

  /**
   * Reloads a source entity without the memory cache.
   */
  protected function source(int $id): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_migrate_legacy_item');
    $storage->resetCache([$id]);
    $entity = $storage->load($id);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    return $entity;
  }

  /**
   * Reloads a target entity without the memory cache.
   */
  protected function target(int $id): ?ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_migrate_agency_item');
    $storage->resetCache([$id]);
    $entity = $storage->load($id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Runs an executing copy of the synthetic plugin.
   *
   * @return array<string, mixed>
   *   Run receipt.
   */
  protected function copyAll(): array {
    return $this->container->get('agency_migrate.runner')->run('synthetic_item', 50, TRUE);
  }

  /**
   * Asserts a refusal reason.
   */
  protected function assertRefused(callable $work, string $reason): void {
    try {
      $work();
      $this->fail('Expected a migrate refusal.');
    }
    catch (MigrateRefusal $exception) {
      $this->assertSame($reason, $exception->reason);
    }
  }

  /**
   * Asserts one migrate outbox operation exists and carries no secrets.
   *
   * @return list<array<string, mixed>>
   *   Decoded metadata rows.
   */
  protected function assertMinimizedMigrateEvidence(string $operation): array {
    $rows = [];
    $result = $this->container->get('database')
      ->select('agency_migrate_outbox', 'o')
      ->fields('o', ['metadata'])
      ->condition('operation', $operation)
      ->execute();
    while ($row = $result->fetchAssoc()) {
      $metadata = json_decode($row['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertIsArray($metadata);
      $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
      $this->assertDoesNotMatchRegularExpression(
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\bBearer\b|\.pdf\b|\.docx\b|\.png\b/i',
        $encoded,
      );
      foreach (['email', 'phone', 'token', 'filename', 'sub'] as $key) {
        $this->assertArrayNotHasKey($key, $metadata);
      }
      $rows[] = $metadata;
    }
    $this->assertNotSame([], $rows);
    return $rows;
  }

}
