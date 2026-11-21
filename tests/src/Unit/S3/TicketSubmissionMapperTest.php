<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_intake\TicketSubmissionMapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests ticket field allowlist and Medium priority without vendor ids.
 *
 * @group agency
 */
#[Group('agency')]
final class TicketSubmissionMapperTest extends TestCase {

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
   * Allowlisted fields keep Medium priority and drop private extras.
   */
  public function testMap(): void {
    $result = (new TicketSubmissionMapper())->map([
      'summary' => 'Cannot publish a page',
      'problem' => 'The save action returns an error.',
      'systems_involved' => ['sentinel', 'example_cms'],
      'impact' => 'moderate_limited',
      'urgency' => 'medium',
      'constraints' => 'No production change this week.',
      'desired_outcome' => 'Editors can publish again.',
      'first_name' => 'Ada',
      'last_name' => 'Lovelace',
      'work_email' => 'ada@example.com',
      'organization_name' => 'Example Organization',
      'existing_client' => 'yes',
      'service_consent' => 1,
      'consent_version' => 'ticket-intake-2026-08-19',
      'unexpected_private_field' => 'must not leave',
    ], 7);
    $this->assertSame('ticket-7', $result['external_reference']);
    $this->assertSame('ada@example.com', $result['requester_email']);
    $this->assertSame('Cannot publish a page', $result['summary']);
    $this->assertSame('ticket', $result['route']);
    $this->assertArrayNotHasKey('unexpected_private_field', $result['fields']);
    $this->assertSame('moderate_limited', $result['fields']['impact']);
    $this->assertSame(
      ['name' => 'Medium'],
      $result['request_field_values']['priority'],
    );
    $this->assertArrayNotHasKey(
      'customfield_10004',
      $result['request_field_values'],
    );
    $this->assertArrayNotHasKey(
      'customfield_10068',
      $result['request_field_values'],
    );
  }

  /**
   * Highest urgency cannot raise priority above Medium.
   */
  public function testMapDoesNotHonorHighestPriority(): void {
    $result = (new TicketSubmissionMapper())->map([
      'summary' => 'Urgent outage',
      'work_email' => 'ada@example.com',
      'urgency' => 'highest',
      'impact' => 'not-a-real-option',
    ], 8);
    $this->assertSame(
      ['name' => 'Medium'],
      $result['request_field_values']['priority'],
    );
    $this->assertArrayNotHasKey(
      'customfield_10004',
      $result['request_field_values'],
    );
    $this->assertArrayNotHasKey(
      'customfield_10068',
      $result['request_field_values'],
    );
  }

}
