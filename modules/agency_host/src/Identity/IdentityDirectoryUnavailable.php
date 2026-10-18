<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Indicates that fresh identity state could not be established.
 */
final class IdentityDirectoryUnavailable extends \RuntimeException {}
