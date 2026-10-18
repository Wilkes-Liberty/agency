<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Requires fresh eligible cookie authority independently of form visibility.
 */
final class ConsoleAccess implements ContainerInjectionInterface {

  public function __construct(
    private readonly ActorAccess $actors,
    private readonly AccountProxyInterface $account,
    private readonly SessionConfigurationInterface $sessions,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('agency_staff.access'), $container->get('current_user'), $container->get('session_configuration'), $container->get('request_stack'));
  }

  /**
   * Route access never caches a prior permission or eligible-account result.
   */
  public function access(?Request $request = NULL): AccessResultInterface {
    $request ??= $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    try {
      $this->require($request);
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    catch (Refusal) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
  }

  /**
   * Form API supplies CSRF; all submissions additionally enforce this boundary.
   */
  public function require(Request $request): void {
    // A browser following our no-referrer policy sends an opaque Origin for
    // native form submissions. Fetch Metadata must independently establish
    // same-origin in that case; Drupal Form API still validates its CSRF token.
    $private_form_origin = $request->headers->get('Origin') === 'null'
      && $request->headers->get('Sec-Fetch-Site') === 'same-origin';
    if (!$this->account->isAuthenticated() || !$this->sessions->hasSession($request)
      || $request->headers->has('Authorization')
      || $request->headers->has(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER)
      || (!$request->isMethodSafe() && (!$request->isMethod('POST')
        || ($request->headers->has('Origin') && $request->headers->get('Origin') !== $request->getSchemeAndHttpHost() && !$private_form_origin)))) {
      throw new Refusal('not_found');
    }
    $this->actors->coordinator();
  }

}
