<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Shared inquiry vocabulary for intent, platform and notification subject.
 *
 * Constants are the source of truth in unit tests. An optional catalog
 * may add product names; it never replaces the generic choices. Overlay
 * catalogs, webform option lists and FormProvenance stay host.
 */
final class Inquiry {

  /**
   * Allowed intents keyed by option id, labelled in the source language.
   */
  public const INTENTS = [
    'demo' => 'Demo request',
    'walkthrough' => 'Technical walkthrough',
    'project' => 'Project inquiry',
    'partnership' => 'Partnership inquiry',
    'general' => 'General inquiry',
  ];

  /**
   * Generic platform choices. Product names are not shipped here.
   */
  public const PLATFORMS = [
    'not_sure' => 'Not sure yet',
    'multiple' => 'Several platforms',
  ];

  /**
   * Allowed intents keyed by option id.
   *
   * @return array<string, string>
   *   Intent ids mapped to English labels.
   */
  public static function intents(): array {
    return self::INTENTS;
  }

  /**
   * Generic platforms plus any injected catalog entries.
   *
   * @param array<array-key, mixed> $catalog
   *   Optional product labels keyed by platform id. Default empty.
   *
   * @return array<string, string>
   *   Platform ids mapped to English labels.
   *
   * @throws \InvalidArgumentException
   *   When a catalog key or label is invalid or replaces a generic choice.
   */
  public static function platforms(array $catalog = []): array {
    return self::PLATFORMS + self::catalog($catalog);
  }

  /**
   * Validates on Drupal's native and GraphQL form submission paths.
   *
   * Works without a Drupal container. Constants plus the optional catalog
   * are the allowed values; webform configuration is never loaded.
   *
   * @param array<string, mixed> $data
   *   Untrusted inquiry fields.
   * @param array<array-key, mixed> $catalog
   *   Optional product labels keyed by platform id. Default empty.
   *
   * @return array<string, string>
   *   Field errors keyed by element id. Empty when the payload is valid.
   *
   * @throws \InvalidArgumentException
   *   When a catalog key or label is invalid or replaces a generic choice.
   */
  public static function errors(array $data, array $catalog = []): array {
    $errors = [];
    $intents = self::intents();
    $platforms = self::platforms($catalog);
    $intent = $data['intent'] ?? 'general';
    if (!is_string($intent) || !isset($intents[$intent])) {
      $errors['intent'] = 'Choose how we can help.';
    }
    $platform = $data['platform'] ?? 'not_sure';
    if (!is_string($platform) || !isset($platforms[$platform])) {
      $errors['platform'] = 'Choose a platform or Not sure yet.';
    }
    $message = $data['message'] ?? NULL;
    if (in_array($intent, ['general', 'project', 'partnership'], TRUE)
      && (!is_string($message) || trim($message) === '')) {
      $errors['message'] = 'Tell us briefly what you would like to accomplish.';
    }
    return $errors;
  }

  /**
   * Retain existing field keys while generating the notification subject.
   *
   * @param array<string, mixed> $data
   *   Inquiry fields after validation or from a historical record.
   * @param array<array-key, mixed> $catalog
   *   Optional product labels keyed by platform id. Default empty.
   *
   * @return array<string, mixed>
   *   The same keys with derived subject, intent, platform and consent.
   *
   * @throws \InvalidArgumentException
   *   When a catalog key or label is invalid or replaces a generic choice.
   */
  public static function normalize(array $data, array $catalog = []): array {
    $intents = self::intents();
    $platforms = self::platforms($catalog);
    $intent = $data['intent'] ?? 'general';
    $platform = $data['platform'] ?? 'not_sure';
    $data['intent'] = is_string($intent) && isset($intents[$intent])
      ? $intent
      : 'general';
    $data['platform'] = is_string($platform) && isset($platforms[$platform])
      ? $platform
      : 'not_sure';
    // Intent wording in the subject is the staff-facing enum label, so a
    // visitor-facing option label never renames the inbox category; the
    // platform name is whatever the constants or catalog call it.
    $intent_label = self::INTENTS[$data['intent']] ?? $intents[$data['intent']];
    $platform_label = $platforms[$data['platform']] ?? self::PLATFORMS['not_sure'];
    $subject = $intent_label . ' — ' . $platform_label;
    $organization = preg_replace(
      '/[\r\n\x00-\x1f\x7f]/u',
      '',
      is_string($data['organization'] ?? NULL) ? $data['organization'] : '',
    ) ?? '';
    $data['subject'] = mb_substr($subject . ' — ' . $organization, 0, 200);
    $data['marketing_consent'] = in_array(
      $data['marketing_consent'] ?? NULL,
      [TRUE, 1, '1'],
      TRUE,
    ) ? 1 : 0;
    return $data;
  }

  /**
   * Validates an optional product catalog supplied by the caller.
   *
   * @param array<array-key, mixed> $catalog
   *   Optional product labels keyed by platform id.
   *
   * @return array<string, string>
   *   The same catalog when every entry is valid.
   *
   * @throws \InvalidArgumentException
   *   When a catalog key or label is invalid or replaces a generic choice.
   */
  private static function catalog(array $catalog): array {
    foreach ($catalog as $key => $label) {
      if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)
        || !is_string($label) || trim($label) === '' || mb_strlen($label) > 100
        || preg_match('/[\x00-\x1f\x7f]/', $label)
        || isset(self::PLATFORMS[$key])) {
        throw new \InvalidArgumentException(
          'Inquiry platform keys and labels must be valid and may not '
          . 'replace generic choices.',
        );
      }
    }
    return $catalog;
  }

}
