<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\agency_staff\EvidenceOutbox;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes minimized public outbox health to a trusted operations receiver.
 */
final class EvidenceHealthMonitor {

  public function __construct(
    private readonly EvidenceOutbox $outbox,
    private readonly Settings $settings,
    private readonly StateInterface $state,
    private readonly ClientInterface $http,
    private readonly LoggerInterface $logger,
    private readonly EvidenceLock $lock,
  ) {}

  /**
   * Checks aggregate health and delivers state transitions at least once.
   *
   * This is a trusted cron/CLI port, never a participant or coordinator API.
   */
  public function check(): array {
    $result = ['status' => 'unavailable', 'health' => NULL, 'alert_delivery' => 'not_checked'];
    $locked = FALSE;
    try {
      $locked = $this->lock->acquire('monitor');
      if (!$locked) {
        $result['status'] = 'busy';
      }
      else {
        // Another process may have acknowledged a transition since bootstrap.
        $this->state->resetCache();
        $result = $this->checkLocked();
      }
    }
    catch (\Throwable) {
      $result = ['status' => 'unavailable', 'health' => NULL, 'alert_delivery' => 'not_checked'];
    }
    finally {
      if ($locked) {
        try {
          $this->lock->release('monitor');
        }
        catch (\Throwable) {
          $result = ['status' => 'unavailable', 'health' => NULL, 'alert_delivery' => 'not_checked'];
        }
      }
    }
    return $result;
  }

  /**
   * Reads health and the last acknowledged transition under shared exclusion.
   */
  private function checkLocked(): array {
    $target = HostSettings::get($this->settings, 'evidence_alert', []);
    $configured = is_array($target) ? ($target['after_seconds'] ?? NULL) : NULL;
    if ($configured === NULL || $configured === FALSE || $configured === '') {
      return ['status' => 'disabled', 'health' => NULL, 'alert_delivery' => 'disabled'];
    }
    $threshold = is_string($configured) && preg_match('/^[1-9][0-9]*$/D', $configured)
      ? filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : FALSE;
    $health = NULL;
    try {
      if (!is_int($threshold) || $threshold <= 0) {
        throw new \InvalidArgumentException('Invalid monitoring threshold.');
      }
      $health = $this->outbox->health($threshold);
      $status = $health['degraded'] ? 'degraded' : 'healthy';
    }
    catch (\Throwable) {
      $status = 'unavailable';
    }
    $result = ['status' => $status, 'health' => $health, 'alert_delivery' => 'unchanged'];
    $previous = $this->state->get('agency_host.evidence_alert_status', 'healthy');
    if ($status === $previous) {
      return $result;
    }
    $url = is_array($target) ? ($target['url'] ?? NULL) : NULL;
    $parts = is_string($url) ? parse_url($url) : FALSE;
    $synthetic = $this->settings->get('agency_synthetic_host') === TRUE;
    if (!is_array($parts) || !filter_var($url, FILTER_VALIDATE_URL)
      || isset($parts['user']) || isset($parts['pass'])
      || (($parts['scheme'] ?? '') !== 'https'
        && !($synthetic && ($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === '127.0.0.1'))) {
      $this->logger->warning('Agency evidence alert receiver is not configured.');
      $result['alert_delivery'] = 'unconfigured';
      return $result;
    }
    $payload = [
      'event' => 'agency_evidence_delivery',
      'status' => $status === 'healthy' ? 'recovered' : $status,
      'threshold_seconds' => is_int($threshold) && $threshold > 0 ? $threshold : NULL,
      'pending_count' => $health['pending_count'] ?? NULL,
      'oldest_pending_age_seconds' => $health['oldest_pending_age_seconds'] ?? NULL,
      'pending_attempts' => $health['pending_attempts'] ?? NULL,
    ];
    $headers = ['Accept' => 'application/json'];
    if (is_string($target['token'] ?? NULL) && $target['token'] !== '') {
      $headers['Authorization'] = 'Bearer ' . $target['token'];
    }
    try {
      // A receiver may apply the transition even when its response is lost.
      // Persist uncertainty before HTTP so a later opposite state is sent too.
      $this->state->set('agency_host.evidence_alert_status', 'unacknowledged');
      $response = $this->http->request('POST', $url, [
        'headers' => $headers,
        'json' => $payload,
        'connect_timeout' => 2,
        'timeout' => 5,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'stream' => TRUE,
      ]);
      $statusCode = $response->getStatusCode();
      $response->getBody()->close();
      if ($statusCode < 200 || $statusCode >= 300) {
        throw new \RuntimeException('Alert receiver did not acknowledge delivery.');
      }
      $this->state->set('agency_host.evidence_alert_status', $status);
      $result['alert_delivery'] = 'sent';
    }
    catch (\Throwable) {
      $this->logger->error('Agency evidence alert delivery failed; the transition will retry.');
      $result['alert_delivery'] = 'failed';
    }
    return $result;
  }

}
