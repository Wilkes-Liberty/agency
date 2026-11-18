<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\agency_migrate_test\Entity\SyntheticItem;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Snapshot merges preserve both histories without rewriting stored revisions.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateHistoryMergeTest extends MigrateKernelTestBase {

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
   * The selected default may precede the latest draft; both must be imported.
   */
  public function testMergePreservesBothHistoriesAndExactDefault(): void {
    [$agency, $ids] = $this->histories();
    $expected = $this->historyValues($agency);
    $legacy = $this->source(1);
    $legacy->setNewRevision(TRUE);
    $legacy->set('revision_id', 70);
    $legacy->set('payload', 'Legacy-only revision');
    $legacy->save();
    $legacy_only = $this->writer()->canonicalize($legacy);

    $snapshot = $this->writer()->captureHistory($agency);
    $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');

    foreach ($expected as $id => $fields) {
      $restored = $this->storage('agency_migrate_legacy_item')->loadRevision($id);
      $this->assertInstanceOf(ContentEntityInterface::class, $restored);
      $this->assertSame($fields, $this->writer()->canonicalize($restored));
    }
    $retained = $this->storage('agency_migrate_legacy_item')->loadRevision(70);
    $this->assertInstanceOf(ContentEntityInterface::class, $retained);
    $this->assertSame($legacy_only, $this->writer()->canonicalize($retained));
    $current = $this->source(1);
    $this->assertSame($ids[1], (int) $current->getRevisionId());
    $this->assertSame($expected[$ids[1]], $this->writer()->canonicalize($current));
    $this->assertGreaterThan($ids[1], $ids[2]);
  }

  /**
   * Selecting a historical default must not recompute translation metadata.
   */
  public function testPromotionPreservesUnaffectedTranslationMetadata(): void {
    [$agency, $ids] = $this->histories(TRUE);
    $selected = $this->storage('agency_migrate_agency_item')->loadRevision($ids[1]);
    $this->assertInstanceOf(SyntheticItem::class, $selected);
    $this->assertNull($selected->getTranslation('fr')->get('revision_translation_affected')->value);
    $snapshot = $this->writer()->captureHistory($agency);
    $expected = $this->writer()->canonicalize($selected);
    $legacy = $this->source(1);
    $legacy->setNewRevision(TRUE);
    $legacy->set('revision_id', 70);
    $legacy->getTranslation('fr')->set('payload', 'Legacy French branch');
    $legacy->save();
    $merged = $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
    $this->assertSame($expected, $this->writer()->canonicalize($merged));
    $this->assertNull($merged->getTranslation('fr')->get('revision_translation_affected')->value);
    $retained = $this->storage('agency_migrate_legacy_item')->loadRevision(70);
    $this->assertInstanceOf(ContentEntityInterface::class, $retained);
    $this->assertSame('Legacy French branch', $retained->getTranslation('fr')->get('payload')->value);
  }

  /**
   * Captured values remain authoritative after the live Agency record is gone.
   */
  public function testSnapshotReplaysAfterSourceDeletion(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $expected = $this->historyValues($agency);
    $this->assertSame('00123', $snapshot['revisions'][0]['fields']['payload']['en'][0]['value']);
    $agency->delete();
    $this->writer()->validateHistorySnapshot($snapshot, 'agency_migrate_legacy_item');
    $result = $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
    $this->assertSame($ids[1], (int) $result->getRevisionId());
    $this->assertSame($expected, $this->historyValues($result));
    $this->assertNull($this->target(1));
  }

  /**
   * A same-identity live replacement cannot alter the captured replay payload.
   */
  public function testSnapshotIgnoresLaterSourceMutation(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $agency->set('payload', 'Later uncaptured content');
    $agency->save();
    $merged = $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
    $this->assertSame('Agency default', $merged->get('payload')->value);
    $this->assertSame($ids[1], (int) $merged->getRevisionId());
  }

  /**
   * Overlapping revision content is immutable, including original metadata.
   */
  public function testConflictingExistingRevisionRefusesBeforeImports(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $legacy = $this->source(1);
    $legacy->set('revision_log_message', 'Changed original metadata');
    $legacy->save();
    $before = $this->historyValues($this->source(1));
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item'), 'history_conflict');
    $this->assertSame($before, $this->historyValues($this->source(1)));
    $this->assertNull($this->storage('agency_migrate_legacy_item')->loadRevision($ids[1]));
  }

  /**
   * Even a later colliding revision must be found before the first insertion.
   */
  public function testForeignRevisionCollisionRefusesWholeMerge(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $foreign = $this->storage('agency_migrate_legacy_item')->create([
      'id' => 99,
      'revision_id' => $ids[2],
      'title' => 'Foreign revision owner',
    ]);
    $this->storage('agency_migrate_legacy_item')->restore($foreign);
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item'), 'history_conflict');
    $this->assertNull($this->storage('agency_migrate_legacy_item')->loadRevision($ids[1]));
    $this->assertSame('Foreign revision owner', $this->storage('agency_migrate_legacy_item')->load(99)->label());
    $this->assertSame($ids[0], (int) $this->source(1)->getRevisionId());
  }

  /**
   * Reusing the target integer ID with another UUID never authorizes a merge.
   */
  public function testReplacedTargetIdentityRefusesMerge(): void {
    [$agency] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $this->source(1)->delete();
    $replacement = $this->storage('agency_migrate_legacy_item')->create(['id' => 1, 'title' => 'Replacement']);
    $replacement->save();
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item'), 'history_conflict');
    $this->assertSame('Replacement', $this->source(1)->label());
  }

  /**
   * A UUID already attached to another integer ID cannot be duplicated.
   */
  public function testUuidOwnedByDifferentTargetRefusesMerge(): void {
    [$agency] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $this->source(1)->delete();
    $other = $this->storage('agency_migrate_legacy_item')->create([
      'id' => 99,
      'uuid' => $agency->uuid(),
      'title' => 'Same UUID other ID',
    ]);
    $other->save();
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item'), 'history_conflict');
    $this->assertNull($this->storage('agency_migrate_legacy_item')->load(1));
  }

  /**
   * A missing target is restored from values, including historical defaults.
   */
  public function testSnapshotCanRestoreMissingTarget(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $expected = $this->historyValues($agency);
    $this->source(1)->delete();
    $result = $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
    $this->assertSame($expected, $this->historyValues($result));
    $this->assertSame($ids[1], (int) $result->getRevisionId());
    $this->assertTrue((bool) $this->storage('agency_migrate_legacy_item')->loadRevision($ids[0])->wasDefaultRevision());
    $this->assertFalse((bool) $this->storage('agency_migrate_legacy_item')->loadRevision($ids[2])->wasDefaultRevision());
  }

  /**
   * A later failure invalidates populated revision caches after rollback.
   */
  public function testInterruptedMergeRollsBackHistoryAndCaches(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $before = $this->historyValues($this->source(1));
    $this->container->get('state')->set('agency_migrate_test.interrupt_revision', $ids[2]);
    try {
      $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
      $this->fail('Expected real SQL restore interruption.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Synthetic revision interruption.', $exception->getMessage());
    }
    // No caller reset: the earlier imported revision was cached before failure.
    $storage = $this->storage('agency_migrate_legacy_item');
    $this->assertNull($storage->loadRevision($ids[1]));
    $this->assertNull($storage->loadRevision($ids[2]));
    $cached_current = $storage->load(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $cached_current);
    $this->assertSame($ids[0], (int) $cached_current->getRevisionId());
    $this->assertSame($before, $this->historyValues($this->source(1)));
    $count = $this->container->get('database')->select('agency_migrate_legacy_item_revision', 'r')->countQuery()->execute()->fetchField();
    $this->assertSame(1, (int) $count);
    $this->container->get('state')->delete('agency_migrate_test.interrupt_revision');
    $this->assertSame($ids[1], (int) $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item')->getRevisionId());
  }

  /**
   * Repeated identical imports do not duplicate history or reuse serial IDs.
   */
  public function testRepeatedMergeIsIdempotentAndAdvancesSequence(): void {
    [$agency, $ids] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $result = $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
    $expected = $this->historyValues($result);
    $again = $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item');
    $this->assertSame($expected, $this->historyValues($again));
    $next = $this->storage('agency_migrate_legacy_item')->create(['title' => 'Next automatic identity']);
    $this->assertInstanceOf(ContentEntityInterface::class, $next);
    $next->save();
    $this->assertGreaterThan(1, (int) $next->id());
    $this->assertGreaterThan(max($ids), (int) $next->getRevisionId());
    $again->setNewRevision(TRUE);
    $again->set('payload', 'Next appended revision');
    $again->save();
    $this->assertGreaterThan((int) $next->getRevisionId(), (int) $again->getRevisionId());
  }

  /**
   * Missing values and invalid snapshot claims refuse without changing history.
   *
   * @dataProvider invalidSnapshotCases
   */
  #[DataProvider('invalidSnapshotCases')]
  public function testInvalidSnapshotRefusesWithoutMutation(string $case, string $reason): void {
    [$agency] = $this->histories();
    $snapshot = $this->writer()->captureHistory($agency);
    $before = $this->historyValues($this->source(1));
    switch ($case) {
      case 'duplicate':
        $snapshot['revisions'][] = $snapshot['revisions'][0];
        $snapshot['revision_count']++;
        break;

      case 'missing field':
        unset($snapshot['revisions'][1]['fields']['payload']);
        break;

      case 'missing property':
        unset($snapshot['revisions'][1]['fields']['payload']['en'][0]['value']);
        break;

      case 'wrong identity':
        $snapshot['revisions'][1]['fields']['uuid']['en'][0]['value'] = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        break;

      case 'missing default':
        $snapshot['default_revision_id'] = 999;
        break;

      case 'two defaults':
        $snapshot['revisions'][0]['default'] = TRUE;
        break;

      case 'draft selected':
        $snapshot['revisions'][1]['default'] = FALSE;
        $snapshot['revisions'][2]['default'] = TRUE;
        $snapshot['default_revision_id'] = $snapshot['revisions'][2]['revision_id'];
        break;

      case 'wrong layout':
        $snapshot['layout']['fields']['payload']['properties']['value'] = 'integer';
        break;

      case 'object':
        $snapshot['revisions'][1]['fields']['payload']['en'][0]['value'] = new \stdClass();
        break;

      case 'nonfinite':
        $snapshot['revisions'][1]['fields']['payload']['en'][0]['value'] = INF;
        break;

      case 'missing revision':
        array_pop($snapshot['revisions']);
        break;

      case 'missing translation':
        foreach ($snapshot['revisions'][0]['fields'] as &$translations) {
          unset($translations['fr']);
        }
        break;

      case 'over cardinality':
        $snapshot['revisions'][1]['fields']['payload']['en'][] = $snapshot['revisions'][1]['fields']['payload']['en'][0];
        break;

      case 'version':
        $snapshot['version'] = 99;
        break;
    }
    $this->assertRefused(fn () => $this->writer()->validateHistorySnapshot($snapshot, 'agency_migrate_legacy_item'), $reason);
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'agency_migrate_legacy_item'), $reason);
    $this->assertSame($before, $this->historyValues($this->source(1)));
  }

  /**
   * Malformed and conflicting snapshot claims must be explicitly rejected.
   *
   * @return array<string, array{string, string}>
   *   Mutations and their public refusal reasons.
   */
  public static function invalidSnapshotCases(): array {
    $cases = [];
    $unverified = [
      'duplicate', 'missing field', 'wrong identity', 'missing default',
      'two defaults', 'draft selected', 'object', 'nonfinite', 'version',
      'missing revision', 'missing translation', 'over cardinality',
    ];
    foreach ($unverified as $case) {
      $cases[$case] = [$case, 'history_unverified'];
    }
    foreach (['missing property', 'wrong layout'] as $case) {
      $cases[$case] = [$case, 'incompatible_field_layout'];
    }
    return $cases;
  }

  /**
   * Creates a shared original followed by Agency default and pending revisions.
   *
   * @return array{\Drupal\agency_migrate_test\Entity\SyntheticItem, list<int>}
   *   The Agency default and all Agency revision identifiers.
   */
  private function histories(bool $unchanged_french = FALSE): array {
    $legacy = $this->storage('agency_migrate_legacy_item')->create([
      'title' => 'Snapshot example',
      'payload' => '00123',
      'revision_user' => $this->author->id(),
      'revision_created' => 1000000100,
      'revision_log_message' => 'Original',
    ]);
    $this->assertInstanceOf(SyntheticItem::class, $legacy);
    $legacy->set('formatted', [
      ['value' => 'Original text', 'format' => 'plain_text', 'summary' => 'Original summary'],
      ['value' => 'Second item', 'format' => 'plain_text', 'summary' => NULL],
    ]);
    $legacy->addTranslation('fr', [
      'payload' => 'Premier français',
      'formatted' => [['value' => 'Texte original', 'format' => 'plain_text', 'summary' => 'Résumé']],
    ]);
    $legacy->save();
    $this->copyAll();
    $agency = $this->target(1);
    $this->assertInstanceOf(SyntheticItem::class, $agency);
    $ids = [(int) $agency->getRevisionId()];
    $agency->setNewRevision(TRUE);
    $agency->set('revision_id', 80);
    $agency->set('payload', 'Agency default');
    if (!$unchanged_french) {
      $agency->getTranslation('fr')->set('payload', 'Nouveau français');
      $agency->getTranslation('fr')->set('formatted', []);
    }
    $agency->get('formatted')->get(1)->set('summary', 'Later item summary');
    $agency->setRevisionUserId($this->operator->id());
    $agency->setRevisionCreationTime(1000000200);
    $agency->setRevisionLogMessage('Agency current');
    $agency->save();
    $ids[] = (int) $agency->getRevisionId();
    $agency->setNewRevision(TRUE);
    $agency->set('revision_id', 90);
    $agency->isDefaultRevision(FALSE);
    $agency->set('payload', 'Agency pending');
    $agency->removeTranslation('fr');
    $agency->set('formatted', []);
    $agency->addTranslation('es', [
      'payload' => 'Borrador español',
      'formatted' => [['value' => 'Texto pendiente', 'format' => 'plain_text', 'summary' => 'Resumen']],
    ]);
    $agency->setRevisionUserId($this->author->id());
    $agency->setRevisionCreationTime(1000000300);
    $agency->setRevisionLogMessage('Pending draft');
    $agency->save();
    $ids[] = (int) $agency->getRevisionId();
    $default = $this->target(1);
    $this->assertInstanceOf(SyntheticItem::class, $default);
    return [$default, $ids];
  }

  /**
   * Captures comparison values for every revision before changing either side.
   *
   * @return array<int, array<string, string>>
   *   Canonical persisted revisions.
   */
  private function historyValues(ContentEntityInterface $entity): array {
    $result = [];
    foreach ($this->writer()->loadHistory($entity) as $revision) {
      $result[(int) $revision->getRevisionId()] = $this->writer()->canonicalize($revision);
    }
    return $result;
  }

  /**
   * The shared identity writer under test.
   */
  private function writer(): IdentityPreservingWriter {
    return $this->container->get('agency_migrate.identity_writer');
  }

  /**
   * Synthetic entity storage.
   */
  private function storage(string $type): ContentEntityStorageInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage($type);
    $this->assertInstanceOf(ContentEntityStorageInterface::class, $storage);
    return $storage;
  }

}
