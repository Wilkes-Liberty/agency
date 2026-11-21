<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_commercial\InquiryReviewPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ports InquiryReviewTest denials without Webform or Jira.
 *
 * @group agency
 */
#[Group('agency')]
final class InquiryReviewPolicyTest extends TestCase {

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
   * Access/support, stale tokens, uncertain delivery and machines fail closed.
   */
  public function testReviewDenials(): void {
    $policy = new InquiryReviewPolicy();
    foreach (['account_request', 'ticket_intake'] as $form) {
      $this->assertSame(
        ['decision' => 'deny', 'reason' => 'not_inquiry'],
        $policy->approve($this->facts(['form_id' => $form])),
      );
    }
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'stale_token'],
      $policy->approve($this->facts(['token_match' => FALSE])),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'uncertain_delivery'],
      $policy->approve($this->facts(['delivery_certain' => FALSE])),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'forbidden'],
      $policy->approve($this->facts(['machine_principal' => TRUE])),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'crm_mode'],
      $policy->approve($this->facts(['crm_mode' => 'none'])),
    );
  }

  /**
   * Only a declared inquiry form accepts a sales review.
   */
  public function testUndeclaredInquiryFormIsNotReviewable(): void {
    $policy = new InquiryReviewPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'undeclared_form'],
      $policy->approve($this->facts(['declared' => FALSE])),
    );
  }

  /**
   * Competing source delivery and audit failure do not create opportunities.
   */
  public function testReviewedSourceIntake(): void {
    $policy = new InquiryReviewPolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'concurrent_source'],
      $policy->approve($this->facts(['lock_available' => FALSE])),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'audit_rollback'],
      $policy->approve($this->facts(['audit' => FALSE])),
    );
    $this->assertSame(
      ['decision' => 'allow', 'reason' => NULL],
      $policy->approve($this->facts()),
    );
  }

  /**
   * Declared inquiry review facts.
   *
   * @param array<string, mixed> $override
   *   Fact replacements.
   *
   * @return array<string, mixed>
   *   Adapter facts.
   */
  private function facts(array $override = []): array {
    return $override + [
      'form_id' => 'contact',
      'declared' => TRUE,
      'inquiry_form' => TRUE,
      'crm_mode' => 'review',
      'permitted' => TRUE,
      'machine_principal' => FALSE,
      'token_match' => TRUE,
      'delivery_certain' => TRUE,
      'lock_available' => TRUE,
      'audit' => TRUE,
    ];
  }

}
