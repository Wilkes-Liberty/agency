<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\EventSubscriber\PrivateResponseSubscriber;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Covers language prefixes, failed routing and unrelated response boundaries.
 */
#[CoversClass(PrivateResponseSubscriber::class)]
#[Group('agency_host')]
final class PrivateResponseSubscriberTest extends UnitTestCase {

  /**
   * Localized and aliased responses retain privacy before and after routing.
   */
  #[DataProvider('protectedResponses')]
  public function testProtectedResponse(string $raw_path, string $processed_path, ?string $route_path, int $status, string $method, ?array $expected_payload): void {
    $request = Request::create($raw_path, $method);
    $paths = new CurrentPathStack(new RequestStack());
    $paths->setPath($processed_path, $request);
    $processor = $this->createMock(InboundPathProcessorInterface::class);
    $processor->method('processInbound')->willReturnCallback(static fn(string $path): string => preg_replace('#^/(?:es|pt-br)(?=/)#', '', $path));
    if ($route_path !== NULL) {
      $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($route_path));
    }
    $original = new Response('<html>Private response</html>', $status, ['Content-Type' => 'text/html']);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $original);
    (new PrivateResponseSubscriber($paths, $processor))->protect($event);
    $response = $event->getResponse();
    $this->assertSame($status, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'));
    $this->assertSame('no-cache', $response->headers->get('Pragma'));
    $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    if ($expected_payload === NULL) {
      $this->assertSame($original, $response);
      $this->assertSame('<html>Private response</html>', $response->getContent());
      $this->assertSame('text/html', $response->headers->get('Content-Type'));
    }
    else {
      $this->assertSame($expected_payload, json_decode($response->getContent(), TRUE));
      $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }
  }

  /**
   * Cases that could leak private content or obscure an uncertain mutation.
   */
  public static function protectedResponses(): iterable {
    $console = '/admin/agency/discovery/console';
    $api = '/api/agency/discovery/workspaces';
    foreach (['', '/es', '/pt-br'] as $prefix) {
      foreach ([200, 403, 404, 500] as $status) {
        yield "$prefix console $status" => [$prefix . $console, $console, $console, $status, 'GET', NULL];
      }
      yield "$prefix api success" => [$prefix . $api, $api, $api, 200, 'GET', NULL];
      yield "$prefix api denied" => [
        $prefix . $api, $api, $api, 403, 'GET', ['outcome' => 'refused', 'reason' => 'not_found'],
      ];
      yield "$prefix api missing route" => [
        $prefix . $api . '/invalid', $api . '/invalid', NULL, 404, 'GET',
        ['outcome' => 'refused', 'reason' => 'not_found'],
      ];
      yield "$prefix api wrong method" => [
        $prefix . $api, $api, NULL, 405, 'POST', ['outcome' => 'refused', 'reason' => 'method_not_allowed'],
      ];
      yield "$prefix mutation failure" => [$prefix . $api, $api, $api, 503, 'POST', ['outcome' => 'uncertain']];
      yield "$prefix read failure" => [
        $prefix . $api, $api, $api, 503, 'GET', ['outcome' => 'refused', 'reason' => 'unavailable'],
      ];
      yield "$prefix console missing route" => [
        $prefix . $console . '/invalid', $console . '/invalid', NULL, 404, 'GET', NULL,
      ];
    }
    // Route identity must protect a route reached through an alternate path.
    yield 'aliased console' => ['/conversations', '/conversations', $console, 200, 'GET', NULL];
    yield 'aliased api denial' => [
      '/conversation-data', '/conversation-data', $api, 403, 'GET', ['outcome' => 'refused', 'reason' => 'not_found'],
    ];
    yield 'early unlocalized denial' => [
      $api, $api, NULL, 403, 'GET', ['outcome' => 'refused', 'reason' => 'not_found'],
    ];
    foreach (['es', 'pt-br'] as $langcode) {
      yield "early $langcode api denial" => [
        "/$langcode$api", "/$langcode$api", NULL, 403, 'GET', ['outcome' => 'refused', 'reason' => 'not_found'],
      ];
      yield "early $langcode console denial" => [
        "/$langcode$console", "/$langcode$console", NULL, 403, 'GET', NULL,
      ];
    }
  }

  /**
   * Public and neighboring routes must not become private Agency envelopes.
   */
  public function testUnrelatedResponsesRemainUntouched(): void {
    foreach (['/news', '/api/agency/discovery-extra', '/admin/agency/discovery-extra', '/news/api/agency/discovery'] as $path) {
      $request = Request::create('/es' . $path);
      $paths = new CurrentPathStack(new RequestStack());
      $paths->setPath($path, $request);
      $processor = $this->createMock(InboundPathProcessorInterface::class);
      $processor->method('processInbound')->willReturn($path);
      $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($path));
      $response = new Response('<html>Unrelated error</html>', 404, ['Cache-Control' => 'public, max-age=60']);
      $headers = $response->headers->all();
      $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
      (new PrivateResponseSubscriber($paths, $processor))->protect($event);
      $this->assertSame($response, $event->getResponse());
      $this->assertSame($headers, $response->headers->all());
      $this->assertSame('<html>Unrelated error</html>', $response->getContent());
    }
  }

}
