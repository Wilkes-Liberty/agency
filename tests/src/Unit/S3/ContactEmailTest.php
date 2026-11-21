<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once dirname(__DIR__, 4)
  . '/modules/agency_commercial/src/ContactEmail.php';

use Drupal\agency_commercial\ContactEmail;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Conservative email keys stay narrower than mailbox identity.
 *
 * @group agency
 */
#[Group('agency')]
final class ContactEmailTest extends TestCase {

  /**
   * Canonical keys preserve tags and dots while normalizing Unicode and case.
   */
  public function testKeys(): void {
    $this->assertSame(
      'jane@agency.gov',
      ContactEmail::key('  JANE@AGENCY.GOV '),
    );
    $this->assertSame(
      'é@agency.gov',
      ContactEmail::key(" E\u{0301}@Agency.gov "),
    );
    $this->assertNotSame(
      ContactEmail::key('jane@agency.gov'),
      ContactEmail::key('jane+tag@agency.gov'),
    );
    $this->assertNotSame(
      ContactEmail::key('j.ane@gmail.com'),
      ContactEmail::key('jane@gmail.com'),
    );
    $this->assertSame(
      'jane@gmail.com',
      ContactEmail::hint('j.ane@googlemail.com'),
    );
    $this->assertNull(ContactEmail::hint('jane@agency.gov'));
    $this->assertNotSame(
      ContactEmail::hint('jane+tag@gmail.com'),
      ContactEmail::hint('jane@gmail.com'),
    );
    foreach ([
      'info',
      'sales',
      'webmaster',
      'support',
      'contact',
      'admin',
      'billing',
      'office',
    ] as $local) {
      $this->assertSame(
        'role',
        ContactEmail::kind($local . '@agency.gov'),
      );
    }
    $this->assertSame(
      'person',
      ContactEmail::kind('jane@agency.gov'),
    );
  }

  /**
   * Refuses invalid Unicode encoding before a key is produced.
   */
  public function testInvalidEncodingRefuses(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid email encoding.');
    ContactEmail::key("\xFF\xFE@example.test");
  }

}
