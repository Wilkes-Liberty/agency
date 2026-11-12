<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves current-value preservation across real Drupal field storage.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateFieldPreservationTest extends MigrateKernelTestBase {

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
    foreach (['agency_migrate_legacy_item', 'agency_migrate_agency_item'] as $type) {
      foreach (['formatted' => 'text_with_summary', 'reviewers' => 'entity_reference'] as $name => $field_type) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => $type,
          'type' => $field_type,
          'cardinality' => -1,
          'settings' => $name === 'reviewers' ? ['target_type' => 'user'] : [],
        ])->save();
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => $type,
          'bundle' => $type,
          'translatable' => TRUE,
        ])->save();
      }
    }
  }

  /**
   * Extra deltas, formats, summaries and translated values must survive copy.
   */
  public function testCopyAndRollbackPreserveAllCurrentFieldValues(): void {
    $source = $this->seedSources()[0];
    $source->set('formatted', [
      ['value' => '<p>First</p>', 'format' => 'plain_text', 'summary' => 'First summary'],
      ['value' => '<p>Second</p>', 'format' => 'plain_text', 'summary' => 'Second summary'],
    ]);
    $source->set('reviewers', [
      ['target_id' => $this->operator->id()],
      ['target_id' => $this->author->id()],
    ]);
    $source->addTranslation('fr', [
      'payload' => 'Corps traduit',
      'formatted' => [['value' => 'Texte', 'format' => 'plain_text', 'summary' => 'Résumé']],
      'reviewers' => [['target_id' => $this->author->id()]],
    ]);
    $source->save();
    $source = $this->source((int) $source->id());
    $this->copyAll();
    $target = $this->target((int) $source->id());
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $this->assertTrue($target->hasTranslation('fr'));
    foreach (['en', 'fr'] as $language) {
      foreach (['formatted', 'reviewers', 'payload'] as $field) {
        $this->assertSame($source->getTranslation($language)->get($field)->getValue(), $target->getTranslation($language)->get($field)->getValue());
      }
    }
    $copier = $this->container->get('plugin.manager.agency_migrate.copier')->createInstance('synthetic_item');
    $this->assertSame($copier->canonicalize($source), $copier->canonicalize($target));

    $target->getTranslation('fr')->set('formatted', [
      ['value' => 'Modifié', 'format' => 'plain_text', 'summary' => 'Nouveau résumé'],
      ['value' => 'Encore', 'format' => 'plain_text', 'summary' => 'Deuxième résumé'],
    ]);
    $target->save();
    $target = $this->target((int) $source->id());
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $copier->writeLegacyFromTarget($target);
    $legacy = $this->source((int) $source->id());
    $this->assertSame($target->getTranslation('fr')->get('formatted')->getValue(), $legacy->getTranslation('fr')->get('formatted')->getValue());
  }

  /**
   * Drift outside the first main value must change the field digest.
   */
  public function testReconciliationSeesSecondaryPropertiesAndTranslations(): void {
    $source = $this->seedSources()[0];
    $source->set('formatted', [
      ['value' => 'First', 'format' => 'plain_text', 'summary' => 'Summary'],
      ['value' => 'Second', 'format' => 'plain_text', 'summary' => 'Summary'],
    ]);
    $source->addTranslation('fr', ['payload' => 'Original']);
    $source->save();
    $this->copyAll();
    $target = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $target->get('formatted')->get(1)->set('summary', 'Changed summary');
    $target->getTranslation('fr')->set('payload', 'Changed translation');
    $target->save();
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $fields = array_column(array_filter($report->exceptions(), static fn (array $row): bool => $row['type'] === 'field_drift'), 'field');
    $this->assertContains('formatted', $fields);
    $this->assertContains('payload', $fields);
  }

  /**
   * Incompatible layouts refuse instead of dropping an unknown target field.
   */
  public function testMissingTargetFieldRefusesBeforeCopy(): void {
    $this->seedSources();
    FieldConfig::loadByName('agency_migrate_agency_item', 'agency_migrate_agency_item', 'formatted')->delete();
    $this->assertRefused(fn () => $this->copyAll(), 'incompatible_field_layout');
    $this->assertNull($this->target(1));
  }

  /**
   * Every reference delta and translation is checked independently of JSON.
   */
  public function testReferenceChecksIncludeLaterDeltasAndTranslations(): void {
    $source = $this->seedSources()[1];
    $source->set('related_id', [1, 99]);
    $source->addTranslation('fr', ['related_id' => [1, 98]]);
    $source->save();
    $this->copyAll();
    $report = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [2]);
    $broken = array_values(array_filter($report->exceptions(), static fn (array $row): bool => $row['type'] === 'broken_reference'));
    $this->assertCount(4, $broken);
    $this->assertSame([99, 98, 99, 98], array_column($broken, 'referenced_id'));
    $this->assertSame(['en', 'fr', 'en', 'fr'], array_column($broken, 'langcode'));
    $this->assertSame([1, 1, 1, 1], array_column($broken, 'delta'));
  }

  /**
   * Typed numeric/boolean values and nullable text retain their distinctions.
   */
  public function testCanonicalPropertiesRetainStorageTypesAndNull(): void {
    $source = $this->seedSources()[0];
    $source->set('formatted', [['value' => 'Text', 'format' => 'plain_text', 'summary' => NULL]]);
    $source->addTranslation('fr', ['payload' => 'Texte']);
    $source->save();
    $this->copyAll();
    $source = $this->source(1);
    $target = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $copier = $this->container->get('plugin.manager.agency_migrate.copier')->createInstance('synthetic_item');
    $canonical = $copier->canonicalize($source);
    $this->assertSame(1, json_decode($canonical['id'], TRUE, 512, JSON_THROW_ON_ERROR)['en'][0]['value']);
    $this->assertFalse(json_decode($canonical['default_langcode'], TRUE, 512, JSON_THROW_ON_ERROR)['fr'][0]['value']);
    $this->assertNull(json_decode($canonical['formatted'], TRUE, 512, JSON_THROW_ON_ERROR)['en'][0]['summary']);
    $target->get('formatted')->get(0)->set('summary', '');
    $target->save();
    $target = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $this->assertSame('', $target->get('formatted')->get(0)->get('summary')->getValue());
    $this->assertNotSame($canonical['formatted'], $copier->canonicalize($target)['formatted']);
  }

}
