<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Constructs only the explicitly qualified private SMTP handoff.
 */
class SmtpHandoffFactory {

  public function __construct(private readonly ContainerInterface $container) {}

  /**
   * Retains the parent's injected dependencies without plugin fallback.
   */
  public function create(): PrivateSmtp {
    return PrivateSmtp::create($this->container, [], 'SMTPMailSystem', []);
  }

}
