<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\agency_workforce\InvitationPolicyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Synthetic configured host policy; no product policy defaults.
 */
final class SyntheticPolicy implements InvitationPolicyInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $config,
    private readonly AccountProxyInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function scope(): string {
    $scope = $this->config->get('agency_workforce_test.settings')->get('scope');
    if (!is_string($scope) || $scope === '') {
      throw new InvitationRefusal('configuration');
    }
    return $scope;
  }

  /**
   * {@inheritdoc}
   */
  public function assertIssuerAllowed(): void {
    if (!$this->account->hasPermission('issue agency workforce invitations')) {
      throw new InvitationRefusal('forbidden');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function duration(?int $requested): int {
    $settings = $this->config->get('agency_workforce_test.settings');
    $min = $settings->get('minimum');
    $max = $settings->get('maximum');
    $default = $settings->get('duration');
    if (!is_int($min) || !is_int($max) || !is_int($default) || $min <= 0 || $max < $min) {
      throw new InvitationRefusal('configuration');
    }
    return max($min, min($max, $requested ?? $default));
  }

  /**
   * {@inheritdoc}
   */
  public function language(?string $storedOrRequested): string {
    $settings = $this->config->get('agency_workforce_test.settings');
    $value = $storedOrRequested ?? $settings->get('default_language');
    $allowed = $settings->get('languages');
    if (!is_string($value) || !is_array($allowed) || !in_array($value, $allowed, TRUE)) {
      throw new InvitationRefusal('language');
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function canReconcile(): bool {
    return $this->account->hasPermission('reconcile agency workforce invitations');
  }

}
