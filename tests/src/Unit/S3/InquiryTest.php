<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_intake\Inquiry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests generic inquiry intent, platform and subject derivation.
 *
 * @group agency
 */
#[Group('agency')]
final class InquiryTest extends TestCase {

  /**
   * Optional catalog used only by these proofs. Overlay catalogs stay host.
   */
  private const CATALOG = [
    'example_cms' => 'Example CMS',
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
   * General inquiries need context; demonstration requests have intent.
   */
  public function testIntentValidation(): void {
    $this->assertSame([], Inquiry::errors([
      'intent' => 'demo',
      'platform' => 'not_sure',
    ]));
    $this->assertSame([], Inquiry::errors([
      'intent' => 'demo',
      'platform' => 'example_cms',
    ], self::CATALOG));
    $this->assertSame([], Inquiry::errors(['intent' => 'walkthrough']));
    $this->assertArrayHasKey('message', Inquiry::errors(['intent' => 'general']));
    $this->assertArrayHasKey('message', Inquiry::errors([
      'intent' => 'project',
      'message' => '  ',
    ]));
    $this->assertArrayHasKey('platform', Inquiry::errors([
      'intent' => 'demo',
      'platform' => ['not_sure'],
    ]));
    $this->assertArrayHasKey('intent', Inquiry::errors(['intent' => ['demo']]));
    $this->assertArrayHasKey('platform', Inquiry::errors([
      'intent' => 'demo',
      'platform' => 'example_cms',
    ]));
  }

  /**
   * An optional message is still refused when it is not text.
   */
  public function testNonTextMessageIsRefused(): void {
    $this->assertSame([], Inquiry::errors([
      'intent' => 'demo',
      'platform' => 'multiple',
    ]));
    foreach ([['nested' => ['value']], 7, TRUE] as $message) {
      $this->assertArrayHasKey('message', Inquiry::errors([
        'intent' => 'demo',
        'platform' => 'multiple',
        'message' => $message,
      ]));
    }
  }

  /**
   * Subject is derived, marketing preference must be explicit.
   */
  public function testSubjectAndPreference(): void {
    $data = Inquiry::normalize([
      'intent' => 'demo',
      'platform' => 'example_cms',
      'organization' => "Example\r\nBcc: victim@example.com",
      'subject' => 'Forged',
      'marketing_consent' => 'false',
    ], self::CATALOG);
    $this->assertStringStartsWith(
      'Demo request — Example CMS — Example',
      $data['subject'],
    );
    $this->assertStringNotContainsString("\n", $data['subject']);
    $this->assertSame(0, $data['marketing_consent']);
    $this->assertSame(
      1,
      Inquiry::normalize(['marketing_consent' => '1'])['marketing_consent'],
    );
    $this->assertSame(
      'general',
      Inquiry::normalize(['message' => 'Legacy request'])['intent'],
    );
  }

}
