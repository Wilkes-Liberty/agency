<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Bundled content restores its captured bundle and configurable fields.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateBundledHistoryMergeTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'node'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('es')->save();
    NodeType::create(['type' => 'snapshot_bundle', 'name' => 'Snapshot bundle'])->save();
    FieldStorageConfig::create([
      'field_name' => 'formatted',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'formatted',
      'entity_type' => 'node',
      'bundle' => 'snapshot_bundle',
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Configurable fields require the actual captured bundle at probe creation.
   */
  public function testBundledSnapshotRestoresConfigurableHistory(): void {
    [$current, $before] = $this->history();
    $this->assertSame('Later summary', $current->get('formatted')->get(1)->get('summary')->getValue());
    $after = $this->writer()->captureHistory($current);
    $expected = [];
    foreach ($this->writer()->loadHistory($current) as $revision) {
      $expected[(int) $revision->getRevisionId()] = $this->writer()->canonicalize($revision);
    }
    $current->delete();
    $original = $this->writer()->mergeHistory($before, 'node');
    $this->assertSame('snapshot_bundle', $original->bundle());
    $this->assertSame($before['default_revision_id'], (int) $original->getRevisionId());
    $restored = $this->writer()->mergeHistory($after, 'node');
    $this->assertSame($after['default_revision_id'], (int) $restored->getRevisionId());
    $this->assertSame('snapshot_bundle', $restored->bundle());
    foreach ($expected as $id => $fields) {
      $revision = $this->storage()->loadRevision($id);
      $this->assertInstanceOf(ContentEntityInterface::class, $revision);
      $this->assertSame($fields, $this->writer()->canonicalize($revision));
    }
    $this->assertSame('Later summary', $restored->get('formatted')->get(1)->get('summary')->getValue());
    $this->assertTrue($restored->hasTranslation('fr'));
    $this->assertFalse($restored->hasTranslation('es'));
    $this->assertGreaterThan($after['default_revision_id'], (int) $this->storage()->getLatestRevisionId($restored->id()));
  }

  /**
   * A source bundle absent from target configuration cannot be inferred.
   */
  public function testMissingCapturedBundleRefusesWithoutMutation(): void {
    [$current] = $this->history();
    $snapshot = $this->writer()->captureHistory($current);
    $current->delete();
    foreach ($snapshot['revisions'] as &$revision) {
      foreach ($revision['fields']['type'] as &$items) {
        $items[0]['target_id'] = 'missing_bundle';
      }
    }
    $this->assertRefused(fn () => $this->writer()->validateHistorySnapshot($snapshot, 'node'), 'incompatible_field_layout');
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'node'), 'incompatible_field_layout');
    $this->assertNull($this->storage()->load($snapshot['id']));
  }

  /**
   * Revision and translation bundle identities must agree before creation.
   */
  public function testInconsistentCapturedBundlesRefuseWithoutMutation(): void {
    [$current] = $this->history();
    $snapshot = $this->writer()->captureHistory($current);
    $snapshot['revisions'][1]['fields']['type']['fr'][0]['target_id'] = 'different_bundle';
    $this->assertRefused(fn () => $this->writer()->validateHistorySnapshot($snapshot, 'node'), 'history_unverified');
    $this->assertRefused(fn () => $this->writer()->mergeHistory($snapshot, 'node'), 'history_unverified');
    $this->assertSame('snapshot_bundle', $this->storage()->load($snapshot['id'])->bundle());
  }

  /**
   * Creates actual bundled node revisions, including a newer nondefault draft.
   *
   * @return array{\Drupal\node\Entity\Node, array<string, mixed>}
   *   Current default and snapshot of the original revision.
   */
  private function history(): array {
    $node = Node::create([
      'type' => 'snapshot_bundle',
      'title' => 'Original English',
      'uid' => $this->author->id(),
      'formatted' => [
        ['value' => 'Original text', 'format' => 'plain_text', 'summary' => 'Original summary'],
        ['value' => 'Second item', 'format' => 'plain_text', 'summary' => NULL],
      ],
    ]);
    $node->addTranslation('fr', [
      'title' => 'Original français',
      'formatted' => [['value' => 'Texte français', 'format' => 'plain_text', 'summary' => 'Résumé']],
    ]);
    $node->setRevisionUserId($this->author->id());
    $node->setRevisionCreationTime(1000000100);
    $node->setRevisionLogMessage('Original bundled revision');
    $node->save();
    $before = $this->writer()->captureHistory($node);
    $node->setNewRevision(TRUE);
    $node->setTitle('New English default');
    $node->get('formatted')->get(1)->set('summary', 'Later summary');
    $node->setRevisionUserId($this->operator->id());
    $node->setRevisionCreationTime(1000000200);
    $node->setRevisionLogMessage('New bundled default');
    $node->save();
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(FALSE);
    $node->setTitle('Pending English draft');
    $node->set('formatted', []);
    $node->removeTranslation('fr');
    $node->addTranslation('es', ['title' => 'Borrador español']);
    $node->setRevisionCreationTime(1000000300);
    $node->setRevisionLogMessage('Pending bundled draft');
    $node->save();
    $current = $this->storage()->load($node->id());
    $this->assertInstanceOf(Node::class, $current);
    return [$current, $before];
  }

  /**
   * Node content storage, including revisions.
   */
  private function storage(): ContentEntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * The additive snapshot writer under test.
   */
  private function writer(): IdentityPreservingWriter {
    return $this->container->get('agency_migrate.identity_writer');
  }

}
