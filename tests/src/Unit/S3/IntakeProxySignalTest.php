<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once dirname(__DIR__, 4)
  . '/modules/agency_intake/src/IntakeProxySignal.php';

use Drupal\agency_intake\IntakeProxySignal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves only a fresh HMAC bound to the exact body is trusted.
 *
 * @group agency
 */
#[Group('agency')]
final class IntakeProxySignalTest extends TestCase {

  /**
   * Signed suspicion is trusted only when body, secret and age match.
   */
  public function testSignedSignal(): void {
    $body = '{"query":"submit"}';
    $request = Request::create('/graphql', 'POST', [], [], [], [], $body);
    $signal = '1000:suspected';
    $request->headers->set(IntakeProxySignal::SIGNAL_HEADER, $signal);
    $this->assertFalse(
      IntakeProxySignal::trusted($request, 'test-secret', 1000),
    );

    $request->headers->set(
      IntakeProxySignal::SIGNATURE_HEADER,
      hash_hmac('sha256', $signal . "\n" . $body, 'test-secret'),
    );
    $this->assertTrue(
      IntakeProxySignal::trusted($request, 'test-secret', 1000),
    );
    $this->assertFalse(IntakeProxySignal::trusted($request, '', 1000));
    $this->assertFalse(
      IntakeProxySignal::trusted($request, 'other-secret', 1000),
    );
    $this->assertFalse(
      IntakeProxySignal::trusted($request, 'test-secret', 1061),
    );

    $other = Request::create(
      '/graphql',
      'POST',
      [],
      [],
      [],
      [],
      '{"query":"other"}',
    );
    $other->headers->set(
      IntakeProxySignal::SIGNAL_HEADER,
      $signal,
    );
    $other->headers->set(
      IntakeProxySignal::SIGNATURE_HEADER,
      $request->headers->get(IntakeProxySignal::SIGNATURE_HEADER),
    );
    $this->assertFalse(
      IntakeProxySignal::trusted($other, 'test-secret', 1000),
    );
  }

}
