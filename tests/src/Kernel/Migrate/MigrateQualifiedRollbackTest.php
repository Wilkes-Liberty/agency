<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\agency_migrate\PostCutoverWriter;
use Drupal\agency_migrate\RollbackService;
use Drupal\Core\Entity\ContentEntityInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ordered rollback proves the journal, applies it and seals the handover.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateQualifiedRollbackTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['agency_migrate_operation_test'];

  private const DOMAIN = 'synthetic';
  private const SOURCE = 'agency_migrate_legacy_item';
  private const TARGET = 'agency_migrate_agency_item';
  private const REASON = 'Qualified reversal rehearsal';

  /**
   * Interleaved revised and deleted records retain exact durable outcomes.
   */
  public function testOrderedReplayPreservesHistoryProvenanceAndReceipt(): void {
    $generation = $this->prepareDomain();
    $first = $this->create($generation, 'Created after cutover');
    $second = $this->create($generation, 'Transient post-cutover record');
    $original = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $original);
    $existing = $this->target(2);
    $this->assertInstanceOf(ContentEntityInterface::class, $existing);
    $this->update($generation, $original, 'Updated copied record');
    $this->update($generation, $this->target($first['entity_id']), 'Updated created record');
    $deleted = $this->delete($generation, $this->target($second['entity_id']));
    $removed = $this->delete($generation, $existing);
    $original_lineage = $this->container->get('agency_migrate.lineage')->bySource(self::SOURCE, 2);
    $this->assertIsArray($original_lineage);
    $preview = $this->rollback()->rollback(self::DOMAIN, self::REASON, FALSE, $generation);
    $this->assertSame('dry_run', $preview['status']);
    $this->assertSame(6, $preview['pending']);
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);

    $receipt = $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->assertSame('legacy', $receipt['authority']);
    $this->assertSame(6, $receipt['replayed']);
    $this->assertSame($generation + 1, $receipt['generation']);
    $this->assertSame($receipt, $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation));
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, 'Different reason', TRUE, $generation), 'rollback_stale_generation');
    foreach ([1, $first['entity_id']] as $id) {
      $source = $this->history()->captureHistory($this->source($id));
      $source['source_type'] = self::TARGET;
      $this->assertSame($source, $this->history()->captureHistory($this->target($id)));
    }
    $database = $this->container->get('database');
    $storage = $this->container->get('entity_type.manager')->getStorage(self::SOURCE);
    foreach ([2 => $removed, $second['entity_id'] => $deleted] as $id => $operation) {
      $this->assertNull($storage->load($id));
      $this->assertNull($this->target($id));
      $tombstone = $database->select('agency_migrate_rollback_record', 'r')->fields('r')
        ->condition('domain', self::DOMAIN)->condition('entity_id', $id)->execute()->fetchAssoc();
      $this->assertIsArray($tombstone);
      $this->assertSame($operation['wid'], (int) $tombstone['delete_wid']);
      $this->assertSame($operation['entity_uuid'], $tombstone['entity_uuid']);
    }
    $this->assertSame($original_lineage, $this->container->get('agency_migrate.lineage')->bySource(self::SOURCE, 2));
    $this->assertNull($this->container->get('agency_migrate.lineage')->bySource(self::SOURCE, $first['entity_id']));
    $this->assertSame([], $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
  }

  /**
   * Delivered intents expose a lost create/delete pair even after both vanish.
   */
  public function testDeliveredIntentsExposeMissingCreateDeletePair(): void {
    $generation = $this->prepareDomain();
    $record = $this->create($generation, 'Transient payload');
    $this->delete($generation, $this->target($record['entity_id']));
    $database = $this->container->get('database');
    $database->update('agency_migrate_outbox')->fields(['delivered' => 123])->condition('operation', 'post_cutover_write')->execute();
    $database->delete('agency_migrate_write_log')->condition('domain', self::DOMAIN)->execute();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, $execute, $generation), 'rollback_unqualified');
    }
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
  }

  /**
   * A valid prefix cannot apply before a later operation is fully checked.
   */
  public function testInvalidLaterOperationLeavesLegacyUntouched(): void {
    $generation = $this->prepareDomain();
    $entity = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $this->update($generation, $entity, 'Must remain on Agency');
    $later = $this->create($generation, 'Malformed later journal row');
    $database = $this->container->get('database');
    $database->update('agency_migrate_write_log')->fields(['envelope' => '{}'])->condition('wid', $later['wid'])->execute();
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_unqualified');
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
    $this->assertCount(2, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
  }

  /**
   * The generic transition cannot bypass a nonempty rollback preflight.
   */
  public function testAuthorityTransitionRefusesDirectRollback(): void {
    $generation = $this->prepareDomain();
    $this->create($generation, 'Must replay first');
    $authority = $this->container->get('agency_migrate.authority');
    $this->assertRefused(fn () => $authority->transition(self::DOMAIN, 'legacy', self::REASON, TRUE), 'rollback_unqualified');
    $this->assertSame('agency', $authority->get(self::DOMAIN));
    $this->assertSame($generation, $authority->status()[self::DOMAIN]['version']);
  }

  /**
   * Copies an exact original baseline and advances through real authority.
   */
  private function prepareDomain(): int {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register(self::DOMAIN, 'Register synthetic records');
    $authority->transition(self::DOMAIN, 'shadow', 'Begin shadow');
    $authority->transition(self::DOMAIN, 'agency', 'Complete cutover');
    return $authority->status()[self::DOMAIN]['version'];
  }

  /**
   * Creates a post-cutover record through the durable journal writer.
   */
  private function create(int $generation, string $title): array {
    return $this->writer()->create(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, ['title' => $title]);
  }

  /**
   * Updates a post-cutover record through the durable journal writer.
   */
  private function update(int $generation, ?ContentEntityInterface $entity, string $payload): void {
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $this->writer()->update(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, (int) $entity->id(), $entity->uuid(), ['payload' => $payload]);
  }

  /**
   * Deletes a post-cutover record through the durable journal writer.
   */
  private function delete(int $generation, ?ContentEntityInterface $entity): array {
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    return $this->writer()->delete(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, (int) $entity->id(), $entity->uuid());
  }

  /**
   * Returns the serving journal writer.
   */
  private function writer(): PostCutoverWriter {
    return $this->container->get('agency_migrate.post_cutover_writer');
  }

  /**
   * Returns the serving rollback coordinator.
   */
  private function rollback(): RollbackService {
    return $this->container->get('agency_migrate.rollback');
  }

  /**
   * Returns the history snapshot writer.
   */
  private function history(): IdentityPreservingWriter {
    return $this->container->get('agency_migrate.identity_writer');
  }

}
