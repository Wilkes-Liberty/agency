<?php

declare(strict_types=1);

namespace Drupal\agency_host\EventSubscriber;

use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Applies private transport headers even when routing denies a request.
 */
final class PrivateResponseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber with Drupal's processed request paths.
   */
  public function __construct(
    private readonly CurrentPathStack $currentPath,
    private readonly InboundPathProcessorInterface $pathProcessor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['protect', -100]];
  }

  /**
   * Prevents caching and referrer propagation for every Discovery response.
   */
  public function protect(ResponseEvent $event): void {
    $request = $event->getRequest();
    $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    $path = $route instanceof Route ? $route->getPath() : '';
    if (!preg_match('#^/(?:api|admin)/agency/discovery(?:/|$)#', $path)) {
      // Routing can fail before selecting a route. Drupal's processed path
      // retains the protected boundary after language and alias negotiation.
      $path = $this->currentPath->getPath($request);
    }
    if (!$route instanceof Route && !preg_match('#^/(?:api|admin)/agency/discovery(?:/|$)#', $path)) {
      // Authentication may return a response before routing processed the path.
      $path = $this->pathProcessor->processInbound($request->getPathInfo(), $request);
    }
    if (!preg_match('#^/(?:api|admin)/agency/discovery(?:/|$)#', $path)) {
      return;
    }
    $response = $event->getResponse();
    $payload = json_decode((string) $response->getContent(), TRUE);
    if (!preg_match('#^/admin/agency/discovery/console(?:/|$)#', $path)
      && $response->getStatusCode() >= 400
      && (!is_array($payload) || !in_array($payload['outcome'] ?? NULL, ['refused', 'conflict', 'uncertain'], TRUE))) {
      $server_failure = $response->getStatusCode() >= 500;
      $payload = $server_failure && !$event->getRequest()->isMethodSafe()
        ? ['outcome' => 'uncertain']
        : [
          'outcome' => 'refused',
          'reason' => $server_failure ? 'unavailable' : ($response->getStatusCode() === 405 ? 'method_not_allowed' : 'not_found'),
        ];
      $replacement = new JsonResponse($payload, $response->getStatusCode());
      $replacement->headers->replace($response->headers->all());
      $replacement->headers->set('Content-Type', 'application/json');
      $replacement->headers->remove('Content-Length');
      $event->setResponse($replacement);
      $response = $replacement;
    }
    $headers = $response->headers;
    $headers->set('Cache-Control', 'private, no-store, max-age=0');
    $headers->set('Pragma', 'no-cache');
    $headers->set('Referrer-Policy', 'no-referrer');
    $headers->set('X-Content-Type-Options', 'nosniff');
  }

}
