<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Interprets tenant policy; contains no default organization or policy period.
 */
final class Policy {

  public function __construct(
    private readonly ConfigFactoryInterface $config,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Requires the shared package display-name guard.
   */
  public function name(): string {
    try {
      return agency_require_display_name();
    }
    catch (\LogicException) {
      throw new Refusal('policy_unavailable');
    }
  }

  /**
   * Reads a tenant policy value without supplying a default.
   */
  public function value(string $key): mixed {
    return $this->config->get('agency.settings')->get('policy.' . $key);
  }

  /**
   * Supplies current, validated facts needed before a participant chooses Stop.
   */
  public function retentionFacts(): array {
    $this->deadline('retention_after_close');
    return [
      'retained_information' => $this->policyText('retained_information_statement'),
      'retention_period' => $this->value('retention_after_close'),
    ];
  }

  /**
   * Returns the tenant's public support route, independent of any invitation.
   */
  public function supportContact(): string {
    return $this->policyText('fallback_contact_route');
  }

  /**
   * Refuses missing or malformed participant-facing policy text.
   */
  private function policyText(string $key): string {
    $value = $this->value($key);
    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000 || str_contains($value, "\0")) {
      throw new Refusal('policy_unavailable');
    }
    return trim($value);
  }

  /**
   * Computes a policy deadline using UTC calendar arithmetic.
   */
  public function deadline(string $key, ?int $from = NULL): int {
    return self::addPeriod((string) $this->value($key), $from ?? $this->time->getCurrentTime());
  }

  /**
   * Adds positive ISO periods, clamping months/years before adding days/time.
   */
  public static function addPeriod(string $period, int $from): int {
    $pattern = '/^(?:P(?<weeks>\d+)W|P(?=\d|T\d)(?:(?<years>\d+)Y)?(?:(?<months>\d+)M)?(?:(?<days>\d+)D)?(?:T(?=\d)(?:(?<hours>\d+)H)?(?:(?<minutes>\d+)M)?(?:(?<seconds>\d+(?:\.\d+)?)S)?)?)\z/';
    if (!preg_match($pattern, $period, $parts) || strlen($period) > 80) {
      throw new Refusal('policy_unavailable');
    }
    $number = static fn(string $key): float => (float) ($parts[$key] ?? 0);
    $months = $number('years') * 12 + $number('months');
    $seconds = ($number('weeks') * 7 + $number('days')) * 86400
      + $number('hours') * 3600 + $number('minutes') * 60 + $number('seconds');
    // Bounds prevent overflow, not a tenant retention/visibility default.
    if ($months > 120000 || !is_finite($seconds) || $seconds > PHP_INT_MAX / 4 || ($months === 0.0 && $seconds <= 0)) {
      throw new Refusal('policy_unavailable');
    }
    $date = (new \DateTimeImmutable('@' . $from))->setTimezone(new \DateTimeZone('UTC'));
    if ($months > 0) {
      $day = (int) $date->format('j');
      $date = $date->modify('first day of this month')->modify('+' . (int) $months . ' months');
      $date = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), min($day, (int) $date->format('t')));
    }
    $delta = (int) ceil($seconds);
    if ($date->getTimestamp() > PHP_INT_MAX - $delta) {
      throw new Refusal('policy_unavailable');
    }
    $result = $date->getTimestamp() + $delta;
    if ($result <= $from) {
      throw new Refusal('policy_unavailable');
    }
    return $result;
  }

  /**
   * Validates configuration needed before any invitation preparation.
   */
  public function invitationReady(): void {
    $this->name();
    foreach (['invitation_validity', 'acknowledgement_period', 'follow_up_period'] as $key) {
      $this->deadline($key);
    }
    $this->retentionFacts();
    $this->supportContact();
  }

}
