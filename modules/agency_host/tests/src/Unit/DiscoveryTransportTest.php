<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\DiscoveryTransport;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Covers ambiguous persistence and JSON object boundaries before routing fixes.
 */
#[CoversClass(DiscoveryTransport::class)]
#[Group('agency_host')]
final class DiscoveryTransportTest extends UnitTestCase {

  /**
   * Empty objects are accepted while arrays cannot masquerade as command input.
   */
  public function testObjectInputBoundary(): void {
    $transport = new DiscoveryTransport($this->createMock(FloodInterface::class));
    $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"input":{},"key":"intent","version":1}');
    $body = $transport->body($request);
    $this->assertSame([], $body['input']);
    foreach (['[]', '{"input":[]}'] as $raw) {
      try {
        $transport->body(Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $raw));
        $this->fail('A JSON array crossed the object boundary.');
      }
      catch (BadRequestHttpException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * A failure after a possible commit never claims a rollback or exposes data.
   */
  public function testUnknownMutationOutcomeIsUncertain(): void {
    $transport = new DiscoveryTransport($this->createMock(FloodInterface::class));
    $response = $transport->respond(static fn() => throw new \RuntimeException('private detail'), TRUE, TRUE);
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame(['outcome' => 'uncertain'], json_decode($response->getContent(), TRUE));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
  }

  /**
   * Known pre-command validation remains a definite refusal.
   */
  public function testInvalidBodyIsRefusedBeforeMutation(): void {
    $transport = new DiscoveryTransport($this->createMock(FloodInterface::class));
    $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{');
    $response = $transport->respond(fn() => $transport->body($request), TRUE, TRUE);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(['outcome' => 'refused', 'reason' => 'invalid'], json_decode($response->getContent(), TRUE));
  }

}
