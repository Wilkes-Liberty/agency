<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\agency_host\Identity\IdentityAuthority;
use Drupal\agency_staff\EvidenceOutbox;
use Drupal\agency_staff\Policy;
use Drupal\agency_host\Console\ConsoleApplication;
use Drupal\agency_host\Delivery\DeliveryConfiguration;
use Drupal\agency_host\Identity\BindingHistory;
use Drupal\agency_host\Identity\KeycloakDirectory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;

/**
 * Read-only preflight; never qualifies history or claims connected acceptance.
 */
final class RuntimeReadiness {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $config,
    private readonly TypedConfigManagerInterface $typedConfig,
    private readonly ModuleHandlerInterface $modules,
    private readonly Settings $settings,
    private readonly Policy $policy,
    private readonly KeycloakDirectory $directory,
    private readonly BindingHistory $history,
    private readonly DeliveryConfiguration $delivery,
    private readonly ConsoleApplication $console,
    private readonly EvidenceOutbox $outbox,
    private readonly IdentityAuthority $authority,
  ) {}

  /**
   * Returns only check names and booleans, never settings or subject records.
   */
  public function report(): array {
    $checks = [];
    $checks['postgresql_16'] = $this->passes(fn(): bool => $this->database->driver() === 'pgsql'
      && intdiv((int) $this->database->query('SHOW server_version_num')->fetchField(), 10000) === 16);
    $checks['synthetic_identity_module_absent'] = !$this->modules->moduleExists('agency_host_synthetic');
    $checks['bff_client_configured'] = trim((string) $this->config->get('agency_host.settings')->get('bff_client_id')) !== '';
    $checks['tenant_policy'] = $this->passes($this->policyReady(...));
    $checks['identity_reader_configuration'] = $this->passes($this->directory->configurationReady(...));
    $checks['identity_issuer_alignment'] = $this->passes($this->authority->issuer(...));
    $checks['binding_history'] = $this->passes($this->history->qualificationReady(...));
    $checks['invitation_origin'] = $this->passes($this->console->invitationOrigin(...));
    $checks['invitation_custody'] = $this->passes($this->delivery->read(...));
    $checks['evidence_alert_configuration'] = $this->passes($this->alertReady(...));
    $checks['evidence_drain_health'] = $this->passes(function (): bool {
      if (!$this->alertReady()) {
        return FALSE;
      }
      $target = HostSettings::get($this->settings, 'evidence_alert');
      return !$this->outbox->health((int) $target['after_seconds'])['degraded'];
    });
    return [
      'scope' => 'configuration_and_retained_state_only',
      'configuration_ready' => !in_array(FALSE, $checks, TRUE),
      'participant_channel_enabled' => $this->config->get('agency_host.settings')->get('enabled') === TRUE,
      'checks' => $checks,
    ];
  }

  /**
   * Validates effective policy using the package schema and public policy API.
   */
  private function policyReady(): bool {
    $data = $this->config->get('agency.settings')->get();
    if (count($this->typedConfig->createFromNameAndData('agency.settings', $data)->validate()) !== 0) {
      return FALSE;
    }
    $this->policy->invitationReady();
    if ($this->policy->value('summary_window') !== NULL) {
      $this->policy->deadline('summary_window');
    }
    $reasons = $this->policy->value('close_reasons');
    if (!is_array($reasons) || $reasons === []) {
      return FALSE;
    }
    $ids = [];
    foreach ($reasons as $reason) {
      foreach (['id', 'label', 'message'] as $key) {
        if (!is_string($reason[$key] ?? NULL) || trim($reason[$key]) === '') {
          return FALSE;
        }
      }
      $ids[] = $reason['id'];
    }
    return count($ids) === count(array_unique($ids));
  }

  /**
   * Checks receiver syntax without delivery or alert-state changes.
   */
  private function alertReady(): bool {
    $target = HostSettings::get($this->settings, 'evidence_alert', []);
    $seconds = $target['after_seconds'] ?? NULL;
    $url = $target['url'] ?? NULL;
    $parts = is_string($url) ? parse_url($url) : FALSE;
    return is_string($seconds) && preg_match('/^[1-9][0-9]*$/D', $seconds)
      && filter_var($seconds, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== FALSE
      && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
      && filter_var($url, FILTER_VALIDATE_URL)
      && !isset($parts['user']) && !isset($parts['pass'])
      && !isset($parts['query']) && !isset($parts['fragment'])
      && is_string($target['token'] ?? NULL) && trim($target['token']) !== '';
  }

  /**
   * Unknown or unavailable state fails this check without exposing exceptions.
   */
  private function passes(callable $check): bool {
    try {
      return $check() !== FALSE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
