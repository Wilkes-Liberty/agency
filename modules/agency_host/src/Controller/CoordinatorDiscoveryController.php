<?php

declare(strict_types=1);

namespace Drupal\agency_host\Controller;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\InvitationCommands;
use Drupal\agency_staff\RelationshipCommands;
use Drupal\agency_staff\RelationshipReads;
use Drupal\agency_host\DiscoveryTransport;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\SessionConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Same-origin coordinator transport; Agency remains the authorization source.
 */
final class CoordinatorDiscoveryController extends ControllerBase {

  public function __construct(
    private readonly RelationshipReads $reads,
    private readonly RelationshipCommands $commands,
    private readonly InvitationCommands $invitations,
    private readonly AgencyIdentityProvider $identity,
    private readonly DiscoveryTransport $transport,
    private readonly SessionConfigurationInterface $sessions,
    private readonly CsrfTokenGenerator $tokens,
    private readonly ActorAccess $access,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('agency_staff.reads'),
      $container->get('agency_staff.commands'),
      $container->get('agency_staff.invitations'),
      $container->get('agency_host.identity'),
      $container->get('agency_host.transport'),
      $container->get('session_configuration'),
      $container->get('csrf_token'),
      $container->get('agency_staff.access'),
    );
  }

  /**
   * Returns the freshly authorized coordinator queue.
   */
  public function queue(Request $request): JsonResponse {
    return $this->coordinator($request, fn(): array => $this->reads->queue(), FALSE);
  }

  /**
   * Starts Discovery only after selected-subject readback and contact proof.
   */
  public function start(Request $request): JsonResponse {
    return $this->coordinator($request, function () use ($request): array {
      $body = $this->transport->body($request);
      self::requireKeys($body, ['input', 'intended_subject', 'key']);
      if (!is_array($body['input'])
        || !is_array($body['input']['contact'] ?? NULL)
        || !is_string($body['intended_subject']) || !is_string($body['key'])) {
        throw new BadRequestHttpException();
      }
      $input = $body['input'];
      self::requireKeys($input, [
        'contact',
        'display_name',
        'language',
        'next_action',
        'owner_contact',
      ]);
      if (($input['language'] ?? NULL) !== 'en') {
        throw new BadRequestHttpException();
      }
      unset($input['language']);
      // Authorize before any identity lookup or existence-dependent response.
      $this->access->coordinator();
      return $this->identity->withIntendedSubject(
        $body['intended_subject'],
        $input['contact'],
        fn(): array => $this->commands->startDiscovery($input, $body['key']),
      );
    });
  }

  /**
   * Maps coordinator command identifiers to explicit domain methods.
   */
  public function command(string $relationship, string $command, Request $request): JsonResponse {
    return $this->coordinator($request, function () use ($relationship, $command, $request): array {
      $body = $this->transport->body($request);
      self::requireKeys($body, ['input', 'key', 'version']);
      if (!is_array($body['input'])
        || !is_string($body['key']) || !is_int($body['version'])) {
        throw new BadRequestHttpException();
      }
      $input = $body['input'];
      return match ($command) {
        'setNextAction' => $this->commands->setNextAction($relationship, $input, $body['key'], $body['version']),
        'pauseRelationship' => $this->commands->pauseRelationship($relationship, array_replace($input, ['side' => 'agency']), $body['key'], $body['version']),
        'resumeRelationship' => $this->commands->resumeRelationship($relationship, array_replace($input, ['side' => 'agency']), $body['key'], $body['version']),
        'revokeInvitation' => $input === []
          ? $this->invitations->revoke($relationship, $body['key'], $body['version'])
          : ['outcome' => 'refused', 'reason' => 'invalid'],
        'suspendAccess' => $this->commands->suspendAccess($relationship, $input, $body['key'], $body['version']),
        'close' => $this->commands->close($relationship, $input, $body['key'], $body['version']),
        'moveForward' => !array_key_exists('consent', $input)
          ? $this->commands->moveForward($relationship, $input, $body['key'], $body['version'])
          : ['outcome' => 'refused', 'reason' => 'invalid'],
        'issue' => $input === []
          ? $this->invitations->issue($relationship, $body['key'], $body['version'])
          : ['outcome' => 'refused', 'reason' => 'invalid'],
        'resend' => $input === []
          ? $this->invitations->resend($relationship, $body['key'], $body['version'])
          : ['outcome' => 'refused', 'reason' => 'invalid'],
        default => ['outcome' => 'refused', 'reason' => 'invalid'],
      };
    });
  }

  /**
   * Enforces cookie session, same-origin CSRF, and a per-account budget.
   */
  private function coordinator(Request $request, callable $action, bool $post = TRUE): JsonResponse {
    return $this->transport->respond(function () use ($request, $action, $post): array {
      if ($request->headers->has('Authorization')
        || $request->headers->has(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER)
        || !$this->currentUser()->isAuthenticated() || !$this->sessions->hasSession($request)) {
        return ['outcome' => 'refused', 'reason' => 'not_found'];
      }
      if ($post && ($request->getMethod() !== 'POST'
        || !$this->tokens->validate($request->headers->get('X-CSRF-Token', ''), CsrfRequestHeaderAccessCheck::TOKEN_KEY)
        || ($request->headers->has('Origin') && $request->headers->get('Origin') !== $request->getSchemeAndHttpHost()))) {
        return ['outcome' => 'refused', 'reason' => 'not_found'];
      }
      if (!$this->transport->allow('coordinator', (string) $this->currentUser()->id(), 120)) {
        return ['outcome' => 'refused', 'reason' => 'rate_limited'];
      }
      return $action();
    }, FALSE, $post);
  }

  /**
   * Requires one exact envelope independent of JSON object key order.
   */
  private static function requireKeys(array $body, array $expected): void {
    $keys = array_keys($body);
    sort($keys);
    sort($expected);
    if ($keys !== $expected) {
      throw new BadRequestHttpException();
    }
  }

}
