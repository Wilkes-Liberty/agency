<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\agency_staff\EvidenceOutbox;
use Drupal\Core\Site\Settings;

/**
 * Bounded evidence maintenance for a separately supervised operator schedule.
 */
final class EvidenceWorker {

  public function __construct(
    private readonly EvidenceLock $lock,
    private readonly EvidenceOutbox $outbox,
    private readonly EvidenceHealthMonitor $monitor,
    private readonly Settings $settings,
  ) {}

  /**
   * Drains one batch and checks health without overlapping another worker.
   *
   * The supervising process must impose a wall-clock deadline and monitor missed
   * runs. The database session lock has no expiring lease that could admit a
   * second worker while the first is still alive. No record identifiers leave
   * this trusted CLI boundary.
   */
  public function run(): array {
    $report = ['successful' => FALSE, 'status' => 'unavailable', 'drained' => 0, 'alert_delivery' => 'not_checked'];
    $locked = FALSE;
    try {
      if (!$this->configured()) {
        $report['status'] = 'unconfigured';
        return $report;
      }
      $locked = $this->lock->acquire('worker');
      if (!$locked) {
        $report['status'] = 'busy';
        return $report;
      }
      $report['drained'] = $this->outbox->drain(20);
      $health = $this->monitor->check();
      $report['status'] = $health['status'];
      $report['alert_delivery'] = $health['alert_delivery'];
      if ($health['status'] === 'healthy' && $report['drained'] < 20 && ($health['health']['pending_count'] ?? 0) > 0) {
        // A claim error can stop the public drainer before attempt recording.
        // Concurrent new entries also conservatively require another run.
        $report['status'] = 'incomplete';
      }
      $report['successful'] = $report['status'] === 'healthy'
        && in_array($health['alert_delivery'], ['unchanged', 'sent'], TRUE);
    }
    catch (\Throwable) {
      $report['successful'] = FALSE;
      $report['status'] = 'unavailable';
    }
    finally {
      if ($locked) {
        try {
          $this->lock->release('worker');
        }
        catch (\Throwable) {
          $report['successful'] = FALSE;
          $report['status'] = 'unavailable';
        }
      }
    }
    return $report;
  }

  /**
   * Requires explicit authenticated monitoring even while the queue is healthy.
   */
  private function configured(): bool {
    $target = HostSettings::get($this->settings, 'evidence_alert', []);
    if (!is_array($target)) {
      return FALSE;
    }
    $threshold = $target['after_seconds'] ?? NULL;
    $url = $target['url'] ?? NULL;
    $parts = is_string($url) ? parse_url($url) : FALSE;
    return is_string($threshold) && preg_match('/^[1-9][0-9]*$/D', $threshold)
      && filter_var($threshold, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== FALSE
      && is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
      && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
      && !isset($parts['user']) && !isset($parts['pass'])
      && !isset($parts['query']) && !isset($parts['fragment'])
      && is_string($target['token'] ?? NULL) && trim($target['token']) !== '';
  }

}
