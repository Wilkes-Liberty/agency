<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Applies shared bounds, rate limits, and private response semantics.
 */
final class DiscoveryTransport {

  public const MAX_BODY_BYTES = 16384;

  public function __construct(private readonly FloodInterface $flood) {}

  /**
   * Consumes one bounded rate-limit slot.
   */
  public function allow(string $scope, string $identifier, int $limit): bool {
    $key = hash('sha256', $identifier === '' ? 'unknown' : $identifier);
    $event = 'agency_host.' . $scope;
    if (!$this->flood->isAllowed($event, $limit, 60, $key)) {
      return FALSE;
    }
    $this->flood->register($event, 60, $key);
    return TRUE;
  }

  /**
   * Decodes one shallow JSON object and rejects oversized or list bodies.
   */
  public function body(Request $request): array {
    $raw = (string) $request->getContent();
    if ($request->getContentTypeFormat() !== 'json' || $raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
      throw new BadRequestHttpException('Invalid request body.');
    }
    try {
      $object = json_decode($raw, FALSE, 8, JSON_THROW_ON_ERROR);
      if (!$object instanceof \stdClass || (property_exists($object, 'input') && !$object->input instanceof \stdClass)) {
        throw new BadRequestHttpException('Invalid request body.');
      }
      return json_decode($raw, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new BadRequestHttpException('Invalid request body.');
    }
  }

  /**
   * Executes one adapter call and maps only stable public outcomes.
   */
  public function respond(callable $action, bool $participant = FALSE, bool $mutation = FALSE): JsonResponse {
    try {
      $payload = $action();
      if (!is_array($payload)) {
        throw new \UnexpectedValueException();
      }
      $status = $this->status($payload, $participant);
    }
    catch (BadRequestHttpException) {
      $payload = ['outcome' => 'refused', 'reason' => 'invalid'];
      $status = 400;
    }
    catch (Refusal $refusal) {
      $payload = ['outcome' => 'refused', 'reason' => $refusal->reason];
      $status = $this->status($payload, $participant);
    }
    catch (\Throwable) {
      $payload = $mutation ? ['outcome' => 'uncertain'] : ['outcome' => 'refused', 'reason' => 'unavailable'];
      $status = 503;
    }
    return $this->response($payload, $status);
  }

  /**
   * Returns a rate-limit response with the same privacy headers.
   */
  public function limited(): JsonResponse {
    return $this->response(['outcome' => 'refused', 'reason' => 'rate_limited'], 429);
  }

  /**
   * Maps domain outcomes without exposing storage or exception detail.
   */
  private function status(array &$payload, bool $participant): int {
    if (($payload['outcome'] ?? NULL) === 'uncertain') {
      return 503;
    }
    if (($payload['outcome'] ?? NULL) === 'conflict') {
      return 409;
    }
    if (($payload['outcome'] ?? NULL) !== 'refused') {
      return 200;
    }
    $reason = is_string($payload['reason'] ?? NULL) ? $payload['reason'] : 'unavailable';
    if ($participant && $reason === 'not_found') {
      $payload = ['outcome' => 'refused', 'reason' => 'not_found'];
    }
    return match ($reason) {
      'not_found', 'invitation_closed' => 404,
      'wrong_account' => 403,
      'signed_out' => 401,
      'already_used', 'idempotency_conflict' => 409,
      'identity_unavailable', 'policy_unavailable', 'recording_unavailable', 'unavailable' => 503,
      'method_not_allowed' => 405,
      'rate_limited' => 429,
      'contact_refused', 'identity_not_found' => 422,
      default => 400,
    };
  }

  /**
   * Prevents shared caching and referrer propagation for every outcome.
   */
  private function response(array $payload, int $status): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Referrer-Policy', 'no-referrer');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
