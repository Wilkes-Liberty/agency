<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_intake\BusinessSubmissionMapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests identity, consent and field boundaries for business intake delivery.
 *
 * @group agency
 */
#[Group('agency')]
final class BusinessSubmissionMapperTest extends TestCase {

  /**
   * Injected contracts. Overlay PHP stays host.
   */
  private const MAPPINGS = [
    'contact' => 'inquiry',
    'account_request' => 'account',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_intake\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_intake/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Inquiry routing preserves final intent, attribution and unchecked consent.
   */
  public function testInquiry(): void {
    $result = $this->map('contact', [
      'email' => 'requester@example.com',
      'subject' => "Technical walkthrough — Example CMS\r\nExample",
      'intent' => 'walkthrough',
      'platform' => 'example_cms',
      'entry_intent' => 'demo',
      'utm_id' => 'campaign-1',
      'service_consent' => 1,
      'marketing_consent' => 0,
      'consent_version' => 'inquiry-2026-09-06',
      'private_token' => 'must-not-leave',
    ]);
    $this->assertSame('requester@example.com', $result['requester_email']);
    $this->assertSame('contact-42', $result['external_reference']);
    $this->assertSame(
      'Technical walkthrough — Example CMS Example',
      $result['summary'],
    );
    $this->assertSame('walkthrough', $result['fields']['intent']);
    $this->assertSame('demo', $result['fields']['entry_intent']);
    $this->assertSame(0, $result['fields']['marketing_consent']);
    $this->assertSame('campaign-1', $result['fields']['utm_id']);
    $this->assertArrayNotHasKey('private_token', $result['fields']);
  }

  /**
   * Account access preserves terms and uses its own identity field.
   */
  public function testAccount(): void {
    $result = $this->map('account_request', [
      'work_email' => 'account@example.com',
      'email' => 'wrong@example.com',
      'organization_name' => str_repeat('A', 300),
      'why' => 'Review our project',
      'terms_consent' => 1,
      'resume' => 'private-file',
    ]);
    $this->assertSame('account@example.com', $result['requester_email']);
    $this->assertSame('account_request-42', $result['external_reference']);
    $this->assertSame(200, mb_strlen($result['summary']));
    $this->assertSame(1, $result['fields']['terms_consent']);
    $this->assertArrayNotHasKey('resume', $result['fields']);
    $this->assertArrayNotHasKey('email', $result['fields']);
  }

  /**
   * This adapter cannot accidentally export hiring or staff data.
   */
  public function testUnsupportedForm(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->map('staff_onboard', ['work_email' => 'staff@example.com']);
  }

  /**
   * Maps a synthetic saved submission.
   *
   * @param string $form_id
   *   Saved form identifier.
   * @param array<string, mixed> $data
   *   Submitted values.
   *
   * @return array<string, mixed>
   *   Mapped delivery payload.
   */
  private function map(string $form_id, array $data): array {
    return (new BusinessSubmissionMapper(self::MAPPINGS))->map(
      $form_id,
      $data,
      42,
    );
  }

}
