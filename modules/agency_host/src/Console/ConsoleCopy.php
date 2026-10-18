<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

/**
 * Reviewed English console copy; callers render values as escaped plain text.
 */
final class ConsoleCopy {

  /**
   * Reviewed string hierarchy.
   *
   * @var array
   */
  private array $catalog;

  public function __construct() {
    $this->catalog = json_decode(file_get_contents(__DIR__ . '/../../resources/coordinator.en.json'), TRUE, 16, JSON_THROW_ON_ERROR)['coordinatorConsole'];
  }

  /**
   * Resolves a reviewed key without interpreting any content as markup.
   */
  public function text(string $key, array $values = []): string {
    $value = $this->catalog;
    foreach (explode('.', $key) as $part) {
      $value = $value[$part] ?? NULL;
    }
    if (!is_string($value)) {
      throw new \LogicException('Unknown console copy key.');
    }
    $replacements = [];
    foreach ($values as $name => $replacement) {
      $replacements['{' . $name . '}'] = (string) $replacement;
    }
    return strtr($value, $replacements);
  }

  /**
   * Gives stable, operation-specific recovery wording.
   */
  public function error(array $result, string $command = ''): string {
    if (($result['reason'] ?? '') === 'prior_unconfirmed') {
      return $this->text('recovery.blocked');
    }
    $key = match ($result['outcome'] ?? '') {
      'uncertain' => match ($command) {
        'issue' => 'uncertainIssue',
        'resend' => 'uncertainInvitation',
        default => 'uncertain',
      },
      'conflict' => 'conflict',
      default => match ($result['reason'] ?? '') {
        'not_found' => 'notFound',
        'contact_refused' => 'contactRefused',
        'identity_not_found' => 'identityNotFound',
        'identity_unavailable' => 'identityUnavailable',
        'recording_unavailable' => 'recordingUnavailable',
        'policy_unavailable' => 'policyUnavailable',
        'invitation_origin_unavailable' => 'invitationLinkUnavailable',
        'rate_limited' => 'rateLimited',
        'unavailable' => 'uncertain',
        default => 'invalid',
      },
    };
    $text = $this->text('errors.' . $key);
    if (in_array($key, [
      'notFound',
      'identityUnavailable',
      'recordingUnavailable',
      'policyUnavailable',
      'invitationLinkUnavailable',
    ], TRUE)) {
      $text .= ' ' . $this->text('errors.checkFirst');
    }
    return $text;
  }

}
