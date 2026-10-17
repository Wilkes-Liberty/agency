<?php

declare(strict_types=1);

namespace Drupal\agency_host\Controller;

use Drupal\agency_staff\InvitationCommands;
use Drupal\agency_staff\RelationshipCommands;
use Drupal\agency_staff\RelationshipReads;
use Drupal\agency_host\DiscoveryTransport;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Private participant transport backed only by Agency projections and commands.
 */
final class ParticipantDiscoveryController extends ControllerBase {

  public function __construct(
    private readonly RelationshipReads $reads,
    private readonly RelationshipCommands $commands,
    private readonly InvitationCommands $invitations,
    private readonly DiscoveryTransport $transport,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('agency_staff.reads'),
      $container->get('agency_staff.commands'),
      $container->get('agency_staff.invitations'),
      $container->get('agency_host.transport'),
    );
  }

  /**
   * Lists current authorized participant workspaces.
   */
  public function workspaces(Request $request): JsonResponse {
    return $this->participant($request, fn(): array => $this->reads->forActor());
  }

  /**
   * Reads one workspace with indistinguishable missing/cross-person denial.
   */
  public function workspace(string $relationship, Request $request): JsonResponse {
    return $this->participant($request, fn(): array => $this->reads->forActor($relationship));
  }

  /**
   * Lists currently authorized closing summaries.
   */
  public function summaries(Request $request): JsonResponse {
    return $this->participant($request, fn(): array => $this->reads->summaries());
  }

  /**
   * Reads one separately authorized closing summary.
   */
  public function summary(string $relationship, Request $request): JsonResponse {
    return $this->participant($request, fn(): array => $this->reads->summary($relationship));
  }

  /**
   * Inspects one bearer invitation without consuming it.
   */
  public function invitation(string $token, Request $request): JsonResponse {
    if (!$this->transport->allow('invitation_read', (string) $request->getClientIp(), 30)) {
      return $this->transport->limited();
    }
    return $this->transport->respond(fn(): array => $this->invitations->inspect($token), TRUE);
  }

  /**
   * Accepts one invitation using the current exact participant principal.
   */
  public function accept(string $token, Request $request): JsonResponse {
    return $this->participant($request, function () use ($token, $request): array {
      $body = $this->transport->body($request);
      self::requireKeys($body, ['key']);
      if (!is_string($body['key'])) {
        throw new BadRequestHttpException();
      }
      return $this->invitations->accept($token, $body['key']);
    }, 20, 'invitation_accept');
  }

  /**
   * Maps participant-visible command identifiers to explicit domain methods.
   */
  public function command(string $relationship, string $command, Request $request): JsonResponse {
    return $this->participant($request, function () use ($relationship, $command, $request): array {
      $body = $this->transport->body($request);
      self::requireKeys($body, ['input', 'key', 'version']);
      if (!is_array($body['input'])
        || !is_string($body['key']) || !is_int($body['version'])) {
        throw new BadRequestHttpException();
      }
      $input = $body['input'];
      return match ($command) {
        'respond' => $this->commands->respond($relationship, $input, $body['key'], $body['version']),
        'pauseRelationship' => $this->commands->pauseRelationship($relationship, array_replace($input, ['side' => 'participant']), $body['key'], $body['version']),
        'resumeRelationship' => $this->commands->resumeRelationship($relationship, array_replace($input, ['side' => 'participant']), $body['key'], $body['version']),
        'withdrawRelationship' => $this->commands->withdrawRelationship($relationship, $input, $body['key'], $body['version']),
        'moveForward' => ($input['consent'] ?? NULL) === TRUE
          ? $this->commands->moveForward($relationship, $input, $body['key'], $body['version'])
          : ['outcome' => 'refused', 'reason' => 'invalid'],
        default => ['outcome' => 'refused', 'reason' => 'invalid'],
      };
    });
  }

  /**
   * Applies the public-route budget before identity or protected lookup.
   */
  private function participant(Request $request, callable $action, int $limit = 120, ?string $scope = NULL): JsonResponse {
    $scope ??= $request->isMethodSafe() ? 'participant_read' : 'participant_command';
    if (!$this->transport->allow($scope, (string) $request->getClientIp(), $limit)) {
      return $this->transport->limited();
    }
    return $this->transport->respond(function () use ($request, $action): array {
      if (trim((string) $request->headers->get(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER, '')) === '') {
        return ['outcome' => 'refused', 'reason' => 'signed_out'];
      }
      return $action();
    }, TRUE, $request->isMethod('POST'));
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
