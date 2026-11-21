<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Search\Metadata;
use Drupal\agency_commercial\Vocabulary;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Private indexing reads an explicit projection, never arbitrary labels.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class SearchMetadataTest extends CommercialKernelTestBase {

  /**
   * Vocabulary lists only opportunity and activity entity types.
   */
  public function testIndexedTypesAreOpportunityAndActivityOnly(): void {
    $this->assertNotContains('agency_contact', Vocabulary::TYPES);
    foreach (Vocabulary::TYPES as $type) {
      $this->assertStringNotContainsString('contact', $type);
    }
  }

  /**
   * An activity label contains its note; indexing must not read that label.
   */
  public function testActivityDoesNotReadNotesOrDocumentReferences(): void {
    $entity = $this->entity('agency_activity', [
      'type' => 'meeting',
      'occurred_at' => '2026-09-16T12:00:00',
      'changed' => 123,
    ]);
    $values = Metadata::values($entity);
    $this->assertIsArray($values);
    $this->assertSame('Meeting 2026-09-16T12:00:00', $values['title']);
    $this->assertSame(0, $values['owner']);
    $this->assertSame('agency_activity', $values['record_type']);
    $this->assertSame(array_keys(Metadata::TYPES), array_keys($values));
    $this->assertStringNotContainsString('summary', $values['title']);
    $this->assertStringNotContainsString('notes', $values['title']);
  }

  /**
   * Pipeline metadata keeps filters without indexing next-action prose.
   */
  public function testOpportunityFilterMetadata(): void {
    $entity = $this->entity('agency_opportunity', [
      'title' => 'Discovery',
      'organization_name' => 'Synthetic Company',
      'owner' => 7,
      'stage' => 'qualified',
      'next_action_at' => '2026-09-16T12:00:00',
      'changed' => 123,
    ]);
    $values = Metadata::values($entity);
    $this->assertIsArray($values);
    $this->assertSame('Discovery Synthetic Company', $values['title']);
    $this->assertSame(7, $values['owner']);
    $this->assertSame('qualified', $values['stage']);
    $this->assertSame('agency_opportunity', $values['record_type']);
    $this->assertSame(
      strtotime('2026-09-16T12:00:00 UTC'),
      $values['next_action_at'],
    );
  }

  /**
   * Users and nodes cannot enter the private commercial index.
   */
  public function testUnrelatedEntitiesAreRejectedWithoutReadingFields(): void {
    foreach (['user', 'node'] as $type) {
      $entity = $this->entity($type, [], forbid_get: TRUE);
      $this->assertNull(Metadata::values($entity));
      $this->assertNull(Metadata::visibleValues(
        $entity,
        $this->createMock(AccountInterface::class),
      ));
    }
  }

  /**
   * Revoked entity access excludes a stale hit before reading any fields.
   */
  public function testRevokedRecordIsNotPresented(): void {
    $entity = $this->entity('agency_opportunity', [], forbid_get: TRUE);
    $entity->method('access')->willReturn(FALSE);
    $this->assertNull(Metadata::visibleValues(
      $entity,
      $this->createMock(AccountInterface::class),
    ));
  }

  /**
   * A hidden searchable field excludes the hit even if others are visible.
   */
  public function testHiddenSearchableFieldExcludesEntireHit(): void {
    $entity = $this->entity('agency_opportunity', [
      'title' => 'Discovery',
      'organization_name' => 'Synthetic Company',
      'owner' => 7,
      'stage' => 'qualified',
      'next_action_at' => '2026-09-16T12:00:00',
      'changed' => 123,
    ], '', 'stage');
    $entity->method('access')->willReturn(TRUE);
    $this->assertNull(Metadata::visibleValues(
      $entity,
      $this->createMock(AccountInterface::class),
    ));
  }

  /**
   * Visible results use current values instead of a stale indexed label.
   */
  public function testVisibleRecordUsesCurrentMetadata(): void {
    $entity = $this->entity('agency_opportunity', [
      'title' => 'Current title',
      'organization_name' => 'Current organization',
      'owner' => 7,
      'stage' => 'qualified',
      'next_action_at' => '2026-09-16T12:00:00',
      'changed' => 456,
    ]);
    $entity->method('access')->willReturn(TRUE);
    $values = Metadata::visibleValues(
      $entity,
      $this->createMock(AccountInterface::class),
    );
    $this->assertIsArray($values);
    $this->assertSame('Current title Current organization', $values['title']);
    $this->assertSame(456, $values['changed']);
  }

  /**
   * Creates a record that fails if any non-allowlisted field or label is read.
   *
   * @param string $type
   *   Entity type ID.
   * @param array<string, mixed> $values
   *   Allowlisted field values.
   * @param string $bundle
   *   Bundle ID when the type uses bundles.
   * @param string|null $hidden
   *   Field name whose view access is revoked.
   * @param bool $forbid_get
   *   When TRUE, any field read fails the test.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject&\Drupal\Core\Entity\ContentEntityInterface
   *   Mocked record.
   */
  private function entity(
    string $type,
    array $values,
    string $bundle = '',
    ?string $hidden = NULL,
    bool $forbid_get = FALSE,
  ): MockObject&ContentEntityInterface {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('bundle')->willReturn($bundle);
    $entity->expects($this->never())->method('label');
    $entity->method('hasField')->willReturn(TRUE);
    if ($forbid_get) {
      $entity->expects($this->never())->method('get');
      return $entity;
    }
    $entity->method('get')->willReturnCallback(
      function (string $name) use ($values, $hidden): FieldItemListInterface {
        $this->assertArrayHasKey(
          $name,
          $values,
          'Unexpected indexed field: ' . $name,
        );
        $field = $this->createMock(FieldItemListInterface::class);
        $field->method('access')->willReturn($name !== $hidden);
        $field->method('__isset')->willReturn(TRUE);
        $field->method('__get')->willReturn($values[$name]);
        return $field;
      },
    );
    return $entity;
  }

}
