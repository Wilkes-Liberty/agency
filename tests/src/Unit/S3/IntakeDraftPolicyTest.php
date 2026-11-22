<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_commercial\IntakeDraftPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ports IntakeDraftTest write boundaries without Webform or Jira.
 *
 * @group agency
 */
#[Group('agency')]
final class IntakeDraftPolicyTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_commercial\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_commercial/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Undeclared forms write nothing.
   */
  public function testUndeclaredFormsWriteNothing(): void {
    $policy = new IntakeDraftPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'undeclared_form'],
      $policy->draft(['declared' => FALSE]),
    );
  }

  /**
   * Claim-only intake never drafts an opportunity.
   */
  public function testClaimOnlyNeverDraftsAnOpportunity(): void {
    $policy = new IntakeDraftPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'claim_only'],
      $policy->draft($this->facts(['claim_only' => TRUE])),
    );
  }

  /**
   * Missing owner fails closed.
   */
  public function testIntakeOwnerFailsClosed(): void {
    $policy = new IntakeDraftPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'owner_missing'],
      $policy->draft($this->facts(['owner_present' => FALSE])),
    );
  }

  /**
   * Audit failure rolls the draft back.
   */
  public function testDraftAuditRollback(): void {
    $policy = new IntakeDraftPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'audit_rollback'],
      $policy->draft($this->facts(['audit' => FALSE])),
    );
  }

  /**
   * A competing first arrival does not create a second draft.
   */
  public function testConcurrentFirstArrival(): void {
    $policy = new IntakeDraftPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'concurrent_first_arrival'],
      $policy->draft($this->facts(['lock_available' => FALSE])),
    );
  }

  /**
   * A declared draft with owner, audit and lock may proceed.
   */
  public function testBusinessDraftsAndReplay(): void {
    $policy = new IntakeDraftPolicy();
    $this->assertSame(
      ['decision' => 'allow', 'reason' => NULL],
      $policy->draft($this->facts()),
    );
  }

  /**
   * Declared draft facts.
   *
   * @param array<string, mixed> $override
   *   Fact replacements.
   *
   * @return array<string, mixed>
   *   Adapter facts.
   */
  private function facts(array $override = []): array {
    return $override + [
      'declared' => TRUE,
      'claim_only' => FALSE,
      'owner_present' => TRUE,
      'audit' => TRUE,
      'lock_available' => TRUE,
      'crm_mode' => 'draft',
    ];
  }

}
