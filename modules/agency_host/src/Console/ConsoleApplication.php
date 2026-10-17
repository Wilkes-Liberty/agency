<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\agency_host\HostSettings;
use Drupal\agency_staff\InvitationCommands;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\RelationshipCommands;
use Drupal\agency_staff\RelationshipReads;
use Drupal\agency_host\DiscoveryTransport;
use Symfony\Component\HttpFoundation\Request;

/**
 * Calls only public Agency contracts and preserves their stable outcomes.
 */
final class ConsoleApplication {

  public function __construct(
    private readonly ConsoleAccess $access,
    private readonly RelationshipReads $reads,
    private readonly RelationshipCommands $commands,
    private readonly InvitationCommands $invitations,
    private readonly DiscoveryTransport $transport,
    private readonly ConsoleStateStore $state,
  ) {}

  /**
   * Reads only the current coordinator's public projection.
   */
  public function queue(Request $request): array {
    return $this->run($request, fn(): array => $this->reads->queue());
  }

  /**
   * Executes the immutable intent captured by a protected form.
   */
  public function command(Request $request, string $id, string $command, array $intent): array {
    return $this->run($request, function () use ($id, $command, $intent): array {
      if (in_array($command, ['issue', 'resend'], TRUE)) {
        $this->invitationOrigin();
      }
      $input = $intent['input'];
      $key = $intent['key'];
      $version = $intent['version'];
      return match ($command) {
        'setNextAction', 'reassign' => $this->commands->setNextAction($id, $input, $key, $version),
        'pauseRelationship' => $this->commands->pauseRelationship($id, $input + ['side' => 'agency'], $key, $version),
        'resumeRelationship' => $this->commands->resumeRelationship($id, ['side' => 'agency'], $key, $version),
        'close' => $this->commands->close($id, $input, $key, $version),
        'moveForward' => $this->commands->moveForward($id, $input, $key, $version),
        'issue' => $this->invitations->issue($id, $key, $version),
        'resend' => $this->invitations->resend($id, $key, $version),
        'revokeInvitation' => $this->invitations->revoke($id, $key, $version),
        default => ['outcome' => 'refused', 'reason' => 'invalid'],
      };
    }, TRUE);
  }

  /**
   * Keeps unresolved console writes separate within this authenticated session.
   *
   * This is not a domain-wide lock or a barrier across independent sessions.
   */
  public function pending(Request $request, string $context): ?array {
    return $this->state->pending($request, $context);
  }

  /**
   * Runs the same frozen request while an earlier outcome remains unresolved.
   */
  public function intent(Request $request, string $context, array $intent, callable $action): array {
    $result = $this->run($request, function () use ($request, $context, $intent, $action): array {
      $reservation = $this->state->reserve($request, $context, $intent);
      if (isset($reservation['settled'])) {
        return $reservation['settled'];
      }
      $result = $this->run($request, $action, TRUE);
      return $this->state->settle($request, $context, $intent, $reservation['ticket'], $result);
    }, TRUE);
    if (($result['reason'] ?? '') === 'unavailable') {
      $result['unconfirmed'] = TRUE;
    }
    return $result;
  }

  /**
   * Requires an explicitly selected invitation audience; no Host fallback.
   */
  public function invitationOrigin(): string {
    $origin = HostSettings::current('invitation_origin', '');
    $parts = is_string($origin) ? parse_url($origin) : FALSE;
    if (!$parts || strlen($origin) > 2048 || ($parts['scheme'] ?? '') !== 'https'
      || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
      || isset($parts['query']) || isset($parts['fragment'])
      || !in_array($parts['path'] ?? '', ['', '/'], TRUE)
      || !filter_var($origin, FILTER_VALIDATE_URL)) {
      throw new Refusal('invitation_origin_unavailable');
    }
    return rtrim($origin, '/');
  }

  /**
   * Normalizes failures without exposing exception details or guessing success.
   */
  public function run(Request $request, callable $action, bool $mutation = FALSE): array {
    $response = $this->transport->respond(function () use ($request, $action, $mutation): array {
      if ($mutation && !$request->isMethod('POST')) {
        throw new Refusal('not_found');
      }
      $this->access->require($request);
      if (!$this->transport->allow('coordinator', (string) \Drupal::currentUser()->id(), 120)) {
        return ['outcome' => 'refused', 'reason' => 'rate_limited'];
      }
      return $action();
    }, FALSE, $mutation);
    return json_decode($response->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);
  }

}
