<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\agency_migrate\Plugin\HistoryCopierInterface;
use Drupal\agency_migrate\RevisionHistorySnapshot;
use Drupal\agency_migrate\SharedSqlMapVerifier;
use Drupal\agency_migrate_test\Entity\SyntheticItem;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Complete revision history survives copy or fails without a partial record.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRevisionHistoryTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('es')->save();
    foreach (['agency_migrate_legacy_item', 'agency_migrate_agency_item'] as $type) {
      FieldStorageConfig::create([
        'field_name' => 'formatted',
        'entity_type' => $type,
        'type' => 'text_with_summary',
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'field_name' => 'formatted',
        'entity_type' => $type,
        'bundle' => $type,
        'translatable' => TRUE,
      ])->save();
    }
  }

  /**
   * Older defaults and newer drafts retain all historical translations.
   */
  public function testHistoryAndDefaultSelectionSurviveCopyAndReplay(): void {
    $revisions = $this->seedHistory();
    $this->copyAll();
    $target_storage = $this->storage('agency_migrate_agency_item');
    $copier = $this->copier();
    foreach ($revisions as $revision_id) {
      $source = $this->revision('agency_migrate_legacy_item', $revision_id);
      $target = $this->revision('agency_migrate_agency_item', $revision_id);
      $this->assertSame($copier->canonicalize($source), $copier->canonicalize($target));
      $this->assertSame($source->isDefaultRevision(), $target->isDefaultRevision());
      $this->assertSame((bool) $source->wasDefaultRevision(), (bool) $target->wasDefaultRevision());
      $this->assertSame($source->getRevisionCreationTime(), $target->getRevisionCreationTime());
      $this->assertSame($source->getRevisionLogMessage(), $target->getRevisionLogMessage());
      $this->assertSame($source->getRevisionUserId(), $target->getRevisionUserId());
    }
    $this->assertSame($revisions[1], (int) $this->target(1)->getRevisionId());
    $this->assertSame($revisions[2], (int) $target_storage->getLatestRevisionId(1));
    $this->assertTrue($this->revision('agency_migrate_agency_item', $revisions[0])->hasTranslation('fr'));
    $this->assertFalse($this->revision('agency_migrate_agency_item', $revisions[2])->hasTranslation('fr'));
    $this->assertTrue($this->revision('agency_migrate_agency_item', $revisions[2])->hasTranslation('es'));
    $second = $this->copyAll();
    $this->assertSame(0, $second['copied']);
    $this->assertSame(1, $second['skipped']);
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $this->assertSame([], $report->exceptions());

    $next = $target_storage->create(['title' => 'Next', 'payload' => 'Later']);
    $this->assertInstanceOf(SyntheticItem::class, $next);
    $next->save();
    $this->assertGreaterThan(1, (int) $next->id());
    $this->assertGreaterThan(max($revisions), (int) $next->getRevisionId());
    $default = $this->target(1);
    $default->setNewRevision(TRUE);
    $default->set('payload', 'New target revision');
    $default->save();
    $this->assertGreaterThan((int) $next->getRevisionId(), (int) $default->getRevisionId());
  }

  /**
   * Map fields retain dynamic typed values across stored revisions.
   */
  public function testStoredMapValuesSurviveSnapshotAndDecode(): void {
    $type = 'agency_migrate_map_item';
    $this->installEntitySchema($type);
    $original = [
      'nested' => ['empty' => [], 'nullable' => NULL, 'ordered' => [0, FALSE, '0']],
      'blank' => '',
    ];
    $replacement = ['nested' => ['empty' => [NULL], 'nullable' => ''], 'blank' => NULL];
    $storage = $this->storage($type);
    $current = $storage->create(['title' => 'Mapped history', 'data' => [$original]]);
    $this->assertInstanceOf(ContentEntityInterface::class, $current);
    $current->save();
    $defaultId = (int) $current->getRevisionId();
    $current->setNewRevision(TRUE);
    $current->isDefaultRevision(FALSE);
    $current->set('data', [$replacement]);
    $current->save();
    $draftId = (int) $current->getRevisionId();

    $default = $this->revision($type, $defaultId);
    $draft = $this->revision($type, $draftId);
    $maps = new SharedSqlMapVerifier($this->container->get('database'), $this->container->get('entity_type.manager'));
    $this->assertRefused(fn () => RevisionHistorySnapshot::capture($default, [$default, $draft]), 'history_unverified');
    $snapshot = RevisionHistorySnapshot::capture($default, [$default, $draft], $maps);
    $langcode = $default->language()->getId();
    $this->assertSame([$original], $snapshot['revisions'][0]['fields']['data'][$langcode]);
    $this->assertSame([$replacement], $snapshot['revisions'][1]['fields']['data'][$langcode]);
    $this->assertSame(2, $snapshot['version']);
    $decoded = RevisionHistorySnapshot::decode($snapshot, $storage);
    $this->assertSame([$original], $decoded[$defaultId]->get('data')->getValue());
    $this->assertSame([$replacement], $decoded[$draftId]->get('data')->getValue());
    $this->assertSame($snapshot['revisions'][0]['fields'], RevisionHistorySnapshot::fields($decoded[$defaultId]));
    $this->assertSame($snapshot['revisions'][1]['fields'], RevisionHistorySnapshot::fields($decoded[$draftId]));

    $draft->set('data', [['forbidden' => new \stdClass()]]);
    $this->assertRefused(fn () => RevisionHistorySnapshot::capture($default, [$default, $draft], $maps), 'history_unverified');
    $oldMap = $snapshot;
    $oldMap['version'] = 1;
    $this->assertRefused(fn () => RevisionHistorySnapshot::decode($oldMap, $storage), 'history_unverified');
    $unknownLayout = $snapshot;
    $unknownLayout['layout']['fields']['data']['properties']['unknown'] = 'string';
    $this->assertRefused(fn () => RevisionHistorySnapshot::decode($unknownLayout, $storage), 'incompatible_field_layout');
    $unknownProperty = $snapshot;
    $unknownProperty['revisions'][0]['fields']['title'][$langcode][0]['unknown'] = 'extension';
    $this->assertRefused(fn () => RevisionHistorySnapshot::decode($unknownProperty, $storage), 'incompatible_field_layout');
  }

  /**
   * Copy, merge and overwrite keep typed maps in real revision storage.
   */
  public function testMapValuesSurvivePersistentWriterPaths(): void {
    $sourceType = 'agency_migrate_map_item';
    $targetType = 'agency_migrate_agency_item';
    $this->installMapSource();
    $this->installMapTarget();
    $sourceStorage = $this->storage($sourceType);
    $map = ['nested' => [9 => NULL, 'ordered' => [0, FALSE, '0']], 'count' => 1, 4 => ''];
    $next = ['nested' => [9 => '', 'ordered' => [1, TRUE, '1']], 'count' => 1.0, 4 => ''];
    $source = $sourceStorage->create(['title' => 'Stored map', 'data' => [$map]]);
    $this->assertInstanceOf(ContentEntityInterface::class, $source);
    $source->addTranslation('fr', ['title' => 'Synthetic translation', 'data' => [['localized' => [NULL, 1.0, []]]]]);
    $source->save();
    $past = (int) $source->getRevisionId();
    $source->setNewRevision(TRUE);
    $source->set('data', [$next]);
    $source->save();
    $writer = $this->container->get('agency_migrate.identity_writer');

    $target = $writer->insertCopy($source, $targetType);
    $this->assertSame([$map], $this->revision($targetType, $past)->get('data')->getValue());
    $this->assertSame([$next], $target->get('data')->getValue());
    $this->assertSame([['localized' => [NULL, 1.0, []]]], $target->getTranslation('fr')->get('data')->getValue());

    $source->setNewRevision(TRUE);
    $source->set('data', [$map]);
    $source->save();
    $expected = $writer->captureHistory($source);
    $target = $writer->mergeHistory($expected, $targetType);
    $this->assertSame([$map], $target->get('data')->getValue());
    $this->assertSame($expected['revisions'], $writer->captureHistory($target)['revisions']);

    $reordered = ['count' => 1, 4 => '', 'nested' => $map['nested']];
    $overwrite = $sourceStorage->create(['title' => 'Stored map', 'data' => [$reordered]]);
    $this->assertInstanceOf(ContentEntityInterface::class, $overwrite);
    $overwrite->addTranslation('fr', ['title' => 'Synthetic translation', 'data' => [['localized' => [NULL, 1.0, []]]]]);
    $overwrite->save();
    $target = $writer->overwriteContent($target, $this->unchanged($sourceStorage, $overwrite->id()));
    $this->assertSame([$reordered], $target->get('data')->getValue());
    $vid = (int) $target->getRevisionId();
    foreach ([array_reverse($map, TRUE), ['01' => 'numeric string key'] + $map] as $numericFirst) {
      $unsupported = clone $source;
      $unsupported->set('data', [$numericFirst]);
      $this->assertRefused(fn () => $writer->overwriteContent($target, $unsupported), 'history_unverified');
      $this->assertSame([$reordered], $this->revision($targetType, $vid)->get('data')->getValue());
    }
    $this->assertRefused(fn () => $writer->mergeHistory($expected, $targetType), 'history_conflict');

    // Reloaded revisions at the same VID can differ only by map key order
    // or by the scalar type of a single item.
    foreach ([$reordered, array_replace($map, ['count' => 1.0])] as $modified) {
      $target->set('data', [$map]);
      $target->setNewRevision(FALSE);
      $target->save();
      $stale = clone $target;
      $target->set('data', [$modified]);
      $target->setNewRevision(FALSE);
      $target->save();
      $this->assertSame($stale->getRevisionId(), $target->getRevisionId());
      $fresh = $this->revision($targetType, (int) $target->getRevisionId());
      $this->assertSame([$modified], $fresh->get('data')->getValue());
      $before = $writer->canonicalize($stale);
      $after = $writer->canonicalize($fresh);
      $this->assertNotSame($before['data'], $after['data']);
      unset($before['data'], $after['data']);
      $this->assertSame($before, $after);
      $this->assertRefused(fn () => $writer->captureHistory($stale), 'history_drift');
      $this->assertRefused(fn () => $writer->mergeHistory($expected, $targetType), 'history_conflict');
    }
  }

  /**
   * Numeric-leading persisted maps cannot pass by flattening on reload.
   */
  public function testPersistedMapHydrationMustMatchSourceStorage(): void {
    $type = 'agency_migrate_map_item';
    $this->installMapSource();
    $storage = $this->storage($type);
    $value = [0 => ['nested' => 'synthetic retained value']];
    $this->installMapTarget();
    $source = $storage->create(['title' => 'Stored map', 'data' => [$value]]);
    $this->assertInstanceOf(ContentEntityInterface::class, $source);
    $source->save();
    $past = (int) $source->getRevisionId();
    $id = (int) $source->id();
    $raw = $this->container->get('database')->select('agency_migrate_map_item_field_revision', 'r')
      ->fields('r', ['data'])->condition('revision_id', $past)->execute()->fetchField();
    $this->assertSame(serialize($value), is_resource($raw) ? stream_get_contents($raw) : $raw);
    $loaded = $storage->loadUnchanged($id);
    $this->assertInstanceOf(ContentEntityInterface::class, $loaded);
    $this->assertSame([['nested' => 'synthetic retained value']], $loaded->get('data')->getValue());
    $writer = $this->container->get('agency_migrate.identity_writer');
    $this->assertRefused(fn () => $writer->captureHistory($loaded), 'history_unverified');
    $target = $this->storage('agency_migrate_agency_item')->create([
      'id' => 99,
      'title' => 'Original title',
      'data' => [['valid' => 'original']],
    ]);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $target->save();
    $this->assertRefused(fn () => $writer->overwriteContent($target, $loaded), 'history_unverified');
    $this->assertSame('Original title', $this->target(99)->label());
    $this->assertSame([['valid' => 'original']], $this->target(99)->get('data')->getValue());

    // Retain the ambiguous map in history after a representable default.
    $source->setNewRevision(TRUE);
    $source->set('data', [['map' => 'current source']]);
    $source->save();
    $fresh = $storage->loadUnchanged($id);
    $this->assertInstanceOf(ContentEntityInterface::class, $fresh);
    $this->assertSame([['map' => 'current source']], $fresh->get('data')->getValue());
    $this->assertRefused(fn () => $writer->captureHistory($fresh), 'history_unverified');
    $this->assertRefused(fn () => $writer->insertCopy($fresh, 'agency_migrate_agency_item'), 'history_unverified');
    $this->assertNull($this->target($id));
  }

  /**
   * Native empty shared maps qualify but ambiguous SQL NULL refuses.
   */
  public function testEmptyUntranslatedMapRequiresNativeSqlShape(): void {
    $type = 'agency_migrate_map_item';
    $this->container->get('state')->set('agency_migrate_test.untranslated_map', TRUE);
    $this->installEntitySchema($type);
    $storage = $this->storage($type);
    $entity = $storage->create(['title' => 'Stored empty map']);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $entity->addTranslation('fr', ['title' => 'Synthetic translation']);
    $entity->save();
    $id = (int) $entity->id();
    $vid = (int) $entity->getRevisionId();
    $maps = new SharedSqlMapVerifier($this->container->get('database'), $this->container->get('entity_type.manager'));
    $expectedTables = [
      'column' => 'fixture_global_map',
      'current' => 'agency_migrate_map_item_field_data',
      'revision' => 'agency_migrate_map_item_field_revision',
    ];
    $this->assertSame($expectedTables, $maps->columns($entity)['fixture_global_map']);
    $database = $this->container->get('database');
    foreach (['current', 'revision'] as $projection) {
      $rows = $database->select($expectedTables[$projection], 'r')
        ->fields('r', ['fixture_global_map'])->condition('id', $id)->condition('revision_id', $vid)
        ->execute()->fetchCol();
      $this->assertCount(2, $rows);
      foreach ($rows as $raw) {
        $this->assertSame(serialize([]), is_resource($raw) ? stream_get_contents($raw) : $raw);
      }
    }
    $writer = $this->container->get('agency_migrate.identity_writer');
    $snapshot = $writer->captureHistory($this->unchanged($storage, $id));
    $this->assertSame(['en' => [], 'fr' => []], $snapshot['revisions'][0]['fields']['fixture_global_map']);

    $database->update($expectedTables['revision'])->fields(['fixture_global_map' => NULL])
      ->condition('id', $id)->condition('revision_id', $vid)->condition('langcode', 'fr')->execute();
    $revision = $this->revision($type, $vid);
    $this->assertTrue($revision->getTranslation('fr')->get('fixture_global_map')->isEmpty());
    $this->assertRefused(fn () => $writer->captureHistory($this->unchanged($storage, $id)), 'history_unverified');
  }

  /**
   * Unsupported dedicated map writes leave other fields and the map untouched.
   */
  public function testDedicatedMapOverwriteRefusesAtomically(): void {
    $type = 'agency_migrate_agency_item';
    FieldStorageConfig::create([
      'entity_type' => $type,
      'field_name' => 'fixture_map',
      'type' => 'map',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'entity_type' => $type,
      'bundle' => $type,
      'field_name' => 'fixture_map',
    ])->save();
    $storage = $this->storage($type);
    $target = $storage->create([
      'title' => 'Original title',
      'fixture_map' => [['value' => ['count' => 1, 'tag' => 'same']]],
    ]);
    $source = $storage->create([
      'title' => 'Overwritten title',
      'fixture_map' => [['value' => ['tag' => 'same', 'count' => 1.0]]],
    ]);
    $target->save();
    $source->save();
    $target = $storage->loadUnchanged($target->id());
    $source = $storage->loadUnchanged($source->id());
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $this->assertInstanceOf(ContentEntityInterface::class, $source);
    $this->assertSame([['value' => ['count' => 1, 'tag' => 'same']]], $target->get('fixture_map')->getValue());
    $this->assertSame([['value' => ['tag' => 'same', 'count' => 1.0]]], $source->get('fixture_map')->getValue());
    $writer = $this->container->get('agency_migrate.identity_writer');
    $this->assertRefused(fn () => $writer->overwriteContent($target, $source), 'incompatible_field_layout');
    $this->assertRefused(fn () => $writer->captureHistory($target), 'incompatible_field_layout');
    $reloaded = $this->unchanged($storage, $target->id());
    $this->assertSame('Original title', $reloaded->label());
    $this->assertSame([['value' => ['count' => 1, 'tag' => 'same']]], $reloaded->get('fixture_map')->getValue());
  }

  /**
   * A shared map changed during save rolls back the title and both SQL rows.
   */
  public function testSharedMapOverwriteReadbackRollsBackOtherFields(): void {
    $this->installMapTarget();
    $type = 'agency_migrate_agency_item';
    $storage = $this->storage($type);
    $original = [['tag' => 'original', 'count' => 1]];
    $entity = $storage->create(['title' => 'Original title', 'data' => $original]);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $entity->save();
    $id = (int) $entity->id();
    $vid = (int) $entity->getRevisionId();
    $candidate = $storage->create(['title' => 'Requested title', 'data' => [['count' => 1.0, 'tag' => 'original']]]);
    $this->assertInstanceOf(ContentEntityInterface::class, $candidate);
    $candidate->save();
    $writer = $this->container->get('agency_migrate.identity_writer');
    $this->container->get('state')->set('agency_migrate_test.rewrite_map', TRUE);
    $this->assertRefused(fn () => $writer->overwriteContent($entity, $candidate), 'history_drift');
    $reloaded = $this->unchanged($storage, $id);
    $this->assertSame('Original title', $reloaded->label());
    $this->assertSame($original, $reloaded->get('data')->getValue());
    $revision = $this->revision($type, $vid);
    $this->assertSame('Original title', $revision->label());
    $this->assertSame($original, $revision->get('data')->getValue());
  }

  /**
   * A foreign connection cannot protect native map storage writes.
   */
  public function testSharedMapOverwriteRefusesForeignConnection(): void {
    $this->installMapTarget();
    $type = 'agency_migrate_agency_item';
    $storage = $this->storage($type);
    $original = [['tag' => 'original', 'count' => 1]];
    $entity = $storage->create(['title' => 'Original title', 'data' => $original]);
    $candidate = $storage->create(['title' => 'Requested title', 'data' => [['tag' => 'candidate', 'count' => 1.0]]]);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $this->assertInstanceOf(ContentEntityInterface::class, $candidate);
    $entity->save();
    $candidate->save();
    $id = (int) $entity->id();
    $vid = (int) $entity->getRevisionId();
    $database = $this->container->get('database');
    Database::addConnectionInfo('migrate_map_foreign', 'default', $database->getConnectionOptions());
    $other = Database::getConnection('default', 'migrate_map_foreign');
    $this->assertNotSame($database->getClientConnection(), $other->getClientConnection());
    $writer = new IdentityPreservingWriter($this->container->get('entity_type.manager'), $other);
    $this->container->get('state')->set('agency_migrate_test.rewrite_map', TRUE);
    $refusal = NULL;
    try {
      $writer->overwriteContent($entity, $candidate);
    }
    catch (MigrateRefusal $exception) {
      $refusal = $exception->reason;
    }
    finally {
      Database::removeConnection('migrate_map_foreign');
    }
    $reloaded = $this->unchanged($storage, $id);
    $this->assertSame('Original title', $reloaded->label());
    $this->assertSame($original, $reloaded->get('data')->getValue());
    $this->assertSame($original, $this->revision($type, $vid)->get('data')->getValue());
    $this->assertSame('writer_connection_mismatch', $refusal);
  }

  /**
   * Installs a map source with the same field layout as the test target.
   */
  private function installMapSource(): void {
    $sourceType = 'agency_migrate_map_item';
    $this->installEntitySchema($sourceType);
    FieldStorageConfig::create([
      'field_name' => 'formatted',
      'entity_type' => $sourceType,
      'type' => 'text_with_summary',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'formatted',
      'entity_type' => $sourceType,
      'bundle' => $sourceType,
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * A revision identifier owned by another target must not be overwritten.
   */
  public function testHistoricalRevisionCollisionRefusesAtomically(): void {
    $revisions = $this->seedHistory();
    $target = $this->storage('agency_migrate_agency_item')->create([
      'id' => 99,
      'revision_id' => $revisions[0],
      'title' => 'Unrelated target',
    ]);
    $target->enforceIsNew(TRUE);
    $this->storage('agency_migrate_agency_item')->restore($target);
    $this->assertRefused(fn () => $this->copyAll(), 'history_conflict');
    $this->assertNull($this->target(1));
    $this->assertSame('Unrelated target', $this->target(99)->label());
    $this->assertSame(0, $this->lineageCount());
  }

  /**
   * Untracked targets are not adopted just because their UUID matches.
   */
  public function testExistingSameUuidWithoutLineageRefuses(): void {
    $this->seedHistory();
    $source = $this->source(1);
    $target = $this->storage('agency_migrate_agency_item')->create([
      'id' => 1,
      'uuid' => $source->uuid(),
      'title' => 'Different untracked content',
    ]);
    $target->save();
    $this->assertRefused(fn () => $this->copyAll(), 'history_unverified');
    $this->assertSame('Different untracked content', $this->target(1)->label());
    $this->assertSame(0, $this->lineageCount());
  }

  /**
   * Missing or edited historical revisions are visible despite current parity.
   */
  public function testMissingHistoryRefusesReplayAndFailsReconciliation(): void {
    $revisions = $this->seedHistory();
    $this->copyAll();
    $this->storage('agency_migrate_agency_item')->deleteRevision($revisions[0]);
    $this->assertRefused(fn () => $this->copyAll(), 'history_drift');
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $this->assertContains('history_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Changed source history invalidates an earlier receipt instead of skipping.
   */
  public function testSourceHistoryChangeRefusesReplay(): void {
    $this->seedHistory();
    $this->copyAll();
    $source = $this->source(1);
    $source->setNewRevision(TRUE);
    $source->isDefaultRevision(FALSE);
    $source->set('payload', 'Another pending revision');
    $source->save();
    $this->assertRefused(fn () => $this->copyAll(), 'history_drift');
  }

  /**
   * A historical metadata change is detected independently of current values.
   */
  public function testHistoricalMetadataDriftFailsReconciliation(): void {
    $revisions = $this->seedHistory();
    $this->copyAll();
    $historic = $this->revision('agency_migrate_agency_item', $revisions[0]);
    $historic->setRevisionLogMessage('Changed historical log');
    $historic->save();
    $this->assertRefused(fn () => $this->copyAll(), 'history_drift');
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $this->assertContains('history_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * The snapshot recheck detects an in-process mutation, not a write fence.
   */
  public function testSourceMutationInsideCopyRefusesBeforeLineageCommit(): void {
    $this->seedHistory();
    $this->container->get('state')->set('agency_migrate_test.change_source_during_copy', TRUE);
    $this->assertRefused(fn () => $this->copyAll(), 'history_drift');
    $this->assertNull($this->storage('agency_migrate_agency_item')->load(1));
    $this->assertNull($this->target(1));
    $this->assertSame(0, $this->lineageCount());
  }

  /**
   * The rollback insertion path has its own atomic history writer boundary.
   */
  public function testRollbackNewRecordWriterIsAtomicWithoutRunner(): void {
    $revisions = $this->seedHistory();
    $this->copyAll();
    $target = $this->target(1);
    $this->source(1)->delete();
    $copier = $this->copier();
    $this->container->get('state')->set('agency_migrate_test.interrupt_revision', $revisions[0]);
    try {
      $copier->writeLegacyFromTarget($target);
      $this->fail('Expected the injected restore failure.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Synthetic revision interruption.', $exception->getMessage());
    }
    $legacy_storage = $this->storage('agency_migrate_legacy_item');
    $this->assertNull($legacy_storage->load(1));
    foreach ($revisions as $revision_id) {
      $this->assertNull($legacy_storage->loadRevision($revision_id));
      $this->assertInstanceOf(ContentEntityInterface::class, $this->storage('agency_migrate_agency_item')->loadRevision($revision_id));
    }
    $this->container->get('state')->delete('agency_migrate_test.interrupt_revision');
    $legacy = $copier->writeLegacyFromTarget($target);
    $this->assertSame($copier->historyManifest($target), $copier->historyManifest($legacy));
  }

  /**
   * A stale default object cannot silently replace newer same-revision content.
   */
  public function testStaleDefaultObjectRefusesDirectRollbackInsert(): void {
    $this->seedHistory();
    $this->copyAll();
    $stale = $this->target(1);
    $fresh = $this->target(1);
    $fresh->set('payload', 'Updated without a new revision');
    $fresh->setNewRevision(FALSE);
    $fresh->save();
    $this->assertSame($stale->getRevisionId(), $fresh->getRevisionId());
    $this->source(1)->delete();
    $this->assertRefused(fn () => $this->copier()->writeLegacyFromTarget($stale), 'history_drift');
    $this->assertNull($this->storage('agency_migrate_legacy_item')->load(1));
  }

  /**
   * An interruption after a historical insert rolls back the whole entity.
   */
  public function testInterruptedHistoryRestoreRollsBackAndCanResume(): void {
    $revisions = $this->seedHistory();
    $this->container->get('state')->set('agency_migrate_test.interrupt_revision', $revisions[0]);
    $this->assertRefused(fn () => $this->copyAll(), 'interrupted');
    $this->assertNull($this->target(1));
    foreach ($revisions as $revision_id) {
      $this->assertNull($this->storage('agency_migrate_agency_item')->loadRevision($revision_id));
    }
    $this->assertSame(0, $this->lineageCount());
    $failed = $this->container->get('database')->select('agency_migrate_run', 'r')->fields('r')->execute()->fetchAssoc();
    $this->container->get('state')->delete('agency_migrate_test.interrupt_revision');
    $receipt = $this->container->get('agency_migrate.runner')->resume($failed['run_id'], TRUE);
    $this->assertSame('completed', $receipt['status']);
    foreach ($revisions as $revision_id) {
      $this->assertInstanceOf(ContentEntityInterface::class, $this->storage('agency_migrate_agency_item')->loadRevision($revision_id));
    }
  }

  /**
   * Existing lineage receives no invented historical qualification on upgrade.
   */
  public function testNullableManifestUpgradePreservesOldLineageAndRefuses(): void {
    $this->seedHistory();
    $this->copyAll();
    $database = $this->container->get('database');
    $database->schema()->dropField('agency_migrate_lineage', 'history_manifest');
    $before = $this->lineageCount();
    $this->container->get('module_handler')->loadInclude('agency_migrate', 'install');
    agency_migrate_update_10001();
    agency_migrate_update_10001();
    $this->assertSame($before, $this->lineageCount());
    $this->assertNull($database->select('agency_migrate_lineage', 'l')->fields('l', ['history_manifest'])->execute()->fetchField());
    $this->assertRefused(fn () => $this->copyAll(), 'history_unverified');
  }

  /**
   * Existing adapters cannot gain history qualification from an old contract.
   */
  public function testAdapterWithoutHistoryCapabilityRefusesBeforeCopy(): void {
    $this->seedHistory();
    $this->assertRefused(fn () => $this->container->get('agency_migrate.runner')->run('legacy_contract', 50, TRUE), 'history_unverified');
    $this->assertNull($this->target(1));
    $this->assertSame(0, $this->lineageCount());
  }

  /**
   * Declaring the history interface cannot qualify an invalid manifest.
   */
  public function testInvalidAdapterManifestRefusesAtomically(): void {
    $this->seedHistory();
    $this->container->get('state')->set('agency_migrate_test.invalid_history_manifest', TRUE);
    $this->assertRefused(fn () => $this->copyAll(), 'history_unverified');
    $this->assertNull($this->target(1));
    $this->assertSame(0, $this->lineageCount());
  }

  /**
   * Malformed historical qualification cannot be mistaken for a valid receipt.
   */
  public function testMalformedManifestRefuses(): void {
    $this->seedHistory();
    $this->copyAll();
    foreach (['{invalid', '{"version":2}', '{"version":1}'] as $manifest) {
      $this->container->get('database')->update('agency_migrate_lineage')->fields(['history_manifest' => $manifest])->execute();
      $this->assertRefused(fn () => $this->copyAll(), 'history_unverified');
    }
  }

  /**
   * A valid manifest cannot conceal a stale or corrupted entity receipt.
   */
  public function testChangedLineageDigestRefusesReplayAndReconciliation(): void {
    $this->seedHistory();
    $this->copyAll();
    $this->container->get('database')->update('agency_migrate_lineage')->fields(['content_digest' => str_repeat('0', 64)])->execute();
    $this->assertRefused(fn () => $this->copyAll(), 'history_drift');
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $this->assertContains('history_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Valid manifests do not conceal a changed lineage identity column.
   */
  public function testChangedLineageUuidFailsReconciliation(): void {
    $this->seedHistory();
    $this->copyAll();
    $this->container->get('database')->update('agency_migrate_lineage')->fields(['target_uuid' => $this->container->get('uuid')->generate()])->execute();
    $this->assertRefused(fn () => $this->copyAll(), 'history_drift');
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $this->assertContains('history_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Builds two historical defaults followed by a newer untranslated draft.
   *
   * @return list<int>
   *   Revision identifiers in creation order.
   */
  private function seedHistory(): array {
    $entity = $this->storage('agency_migrate_legacy_item')->create([
      'title' => 'History',
      'payload' => 'First English',
      'access_class' => 'open',
      'revision_user' => $this->author->id(),
      'revision_created' => 1000000100,
      'revision_log_message' => 'First revision',
    ]);
    $this->assertInstanceOf(SyntheticItem::class, $entity);
    $entity->set('formatted', [
      ['value' => 'First text', 'format' => 'plain_text', 'summary' => 'First summary'],
      ['value' => 'Second delta', 'format' => 'plain_text', 'summary' => NULL],
    ]);
    $entity->addTranslation('fr', [
      'payload' => 'Premier français',
      'formatted' => [['value' => 'Premier texte', 'format' => 'plain_text', 'summary' => 'Résumé']],
    ]);
    $entity->save();
    $ids = [(int) $entity->getRevisionId()];
    $entity->setNewRevision(TRUE);
    $entity->set('payload', 'Second English');
    $entity->get('formatted')->get(1)->set('summary', 'Changed historical summary');
    $entity->getTranslation('fr')->set('formatted', []);
    $entity->getTranslation('fr')->set('payload', 'Deuxième français');
    $entity->setRevisionCreationTime(1000000200);
    $entity->setRevisionUserId($this->operator->id());
    $entity->setRevisionLogMessage('Second default');
    $entity->save();
    $ids[] = (int) $entity->getRevisionId();
    $entity->setNewRevision(TRUE);
    $entity->isDefaultRevision(FALSE);
    $entity->set('payload', 'Unpublished English');
    $entity->set('formatted', []);
    $entity->removeTranslation('fr');
    $entity->addTranslation('es', [
      'payload' => 'Borrador español',
      'formatted' => [['value' => 'Texto pendiente', 'format' => 'plain_text', 'summary' => 'Resumen']],
    ]);
    $entity->setRevisionCreationTime(1000000300);
    $entity->setRevisionUserId($this->author->id());
    $entity->setRevisionLogMessage('Pending draft');
    $entity->save();
    $ids[] = (int) $entity->getRevisionId();
    $this->assertSame($ids[1], (int) $this->source(1)->getRevisionId());
    return $ids;
  }

  /**
   * Loads and checks the concrete revision fixture, including log metadata.
   */
  private function revision(string $type, int $id): SyntheticItem {
    $revision = $this->storage($type)->loadRevision($id);
    $this->assertInstanceOf(SyntheticItem::class, $revision);
    return $revision;
  }

  /**
   * Gets the history-aware adapter under test.
   */
  private function copier(): HistoryCopierInterface {
    $copier = $this->container->get('plugin.manager.agency_migrate.copier')->createInstance('synthetic_item');
    $this->assertInstanceOf(HistoryCopierInterface::class, $copier);
    return $copier;
  }

  /**
   * Loads an entity from storage, bypassing the static cache.
   */
  private function unchanged(EntityStorageInterface $storage, int|string|null $id): ContentEntityInterface {
    $entity = $storage->loadUnchanged($id);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    return $entity;
  }

  /**
   * Entity storage for a synthetic type.
   */
  private function storage(string $type): ContentEntityStorageInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage($type);
    $this->assertInstanceOf(ContentEntityStorageInterface::class, $storage);
    return $storage;
  }

  /**
   * Number of committed entity lineage rows.
   */
  private function lineageCount(): int {
    return (int) $this->container->get('database')->select('agency_migrate_lineage', 'l')->countQuery()->execute()->fetchField();
  }

}
