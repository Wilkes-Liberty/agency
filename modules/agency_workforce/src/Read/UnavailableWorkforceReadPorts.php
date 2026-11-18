<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Read;

/**
 * Refuses access until a host installs trusted workforce read adapters.
 */
final class UnavailableWorkforceReadPorts implements WorkforceReadAuthorityInterface, WorkforceProfileSourceInterface {

  /**
   * {@inheritdoc}
   *
   * @phpstan-pure
   */
  public function current(): ?WorkforceReadAuthority {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function bindingFor(string $personUuid): ?WorkforcePersonBinding {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function readDisplayName(WorkforcePersonBinding $binding): ?string {
    return NULL;
  }

}
