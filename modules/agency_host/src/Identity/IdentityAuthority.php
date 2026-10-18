<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\agency_host\HostSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * One explicit participant authority shared by readiness and identity reads.
 */
final class IdentityAuthority {

  public function __construct(
    private readonly Settings $settings,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * Requires exact issuer alignment and a nonempty dedicated audience.
   */
  public function issuer(): string {
    $identity = HostSettings::get($this->settings, 'identity', []);
    $issuer = is_array($identity) ? ($identity['issuer'] ?? NULL) : NULL;
    $tokens = $this->config->get('agency_host.identity_tokens');
    $token_issuer = $tokens->get('paladin_issuer') ?: $tokens->get('issuer');
    $audience = $tokens->get('paladin_audience') ?: $tokens->get('audience');
    if (!is_string($issuer) || strlen($issuer) > 255 || !filter_var($issuer, FILTER_VALIDATE_URL)
      || $issuer !== rtrim($issuer, '/') || $issuer !== $token_issuer
      || !is_string($audience) || trim($audience) === '' || trim($audience) !== $audience) {
      throw new IdentityDirectoryUnavailable('Identity authority is unavailable.');
    }
    $this->bindingMode();
    return $issuer;
  }

  /**
   * Binding semantics are explicit and cannot silently broaden on typo.
   */
  public function bindingMode(): string {
    $identity = HostSettings::get($this->settings, 'identity', []);
    $mode = is_array($identity) ? ($identity['binding_mode'] ?? NULL) : NULL;
    if (!in_array($mode, ['mapped_or_unbound', 'unbound_only'], TRUE)) {
      throw new IdentityDirectoryUnavailable('Identity binding mode is unavailable.');
    }
    return $mode;
  }

}
