<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_operation_test;

use Drupal\agency_migrate\OperationJournalPolicyInterface;
use Drupal\Core\State\StateInterface;

/**
 * Declares journal protection only for the disposable synthetic test database.
 */
final class OperationTestJournalPolicy implements OperationJournalPolicyInterface {

  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function declaration(string $domain, string $entityType, string $bundle): ?array {
    $case = $this->state->get('agency_operation.policy_case');
    if ($domain !== 'synthetic' || $entityType !== 'agency_migrate_agency_item'
      || !in_array($bundle, [$entityType, 'journal_fixture'], TRUE) || $case === 'missing') {
      return NULL;
    }
    if ($case === 'throws') {
      throw new \RuntimeException('Synthetic private policy diagnostic');
    }
    $markings = [
      'access_class' => ['value' => 'confidential'],
      'changed' => ['value' => 'internal'],
      'created' => ['value' => 'internal'],
      'default_langcode' => ['value' => 'internal'],
      'id' => ['value' => 'internal'],
      'langcode' => ['value' => 'internal'],
      'payload' => ['value' => 'internal'],
      'related_id' => ['value' => 'internal'],
      'revision_created' => ['value' => 'internal'],
      'revision_default' => ['value' => 'internal'],
      'revision_id' => ['value' => 'internal'],
      'revision_log_message' => ['value' => 'internal'],
      'revision_translation_affected' => ['value' => 'internal'],
      'revision_user' => ['target_id' => 'internal'],
      'title' => ['value' => 'public'],
      'uuid' => ['value' => 'internal'],
    ];
    if ($this->state->get('agency_migrate_test.map_target')) {
      $markings['data'] = [];
    }
    if ($bundle === 'journal_fixture') {
      $markings['journal_bundle'] = ['value' => 'internal'];
    }
    if ($case === 'unmarked') {
      $markings['access_class'] = [];
    }
    if ($case === 'unmarked_historical') {
      unset($markings['access_class']);
    }
    if (in_array($case, ['unknown', 'secret'], TRUE)) {
      $markings['access_class']['value'] = $case;
    }
    return [
      'contract_id' => $case === 'changed_contract' ? 'disposable-fixture-v2' : 'disposable-fixture-v1',
      'access_equivalent' => $case !== 'access',
      'recovery_equivalent' => $case !== 'recovery',
      'retention_equivalent' => $case !== 'retention',
      'markings' => $markings,
    ];
  }

}
