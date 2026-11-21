<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Conservative contact-email key, Gmail hint and mailbox classifier.
 *
 * Keys never strip dots or plus-tags. The Gmail hint is a review aid,
 * never a lookup key. The mailbox_kind value is person versus role
 * mailbox, not an organization discriminator.
 */
final class ContactEmail {

  /**
   * Shared mailbox local-parts. mailbox_kind is not an organization flag.
   */
  private const ROLE_LOCALS = [
    'info',
    'sales',
    'webmaster',
    'support',
    'contact',
    'admin',
    'billing',
    'office',
  ];

  /**
   * Normalizes the whole address without removing dots or plus-tags.
   *
   * @param string $email
   *   Untrusted mailbox address.
   *
   * @return string
   *   NFC, trimmed, lowercased address. Dots and plus-tags remain.
   *
   * @throws \InvalidArgumentException
   *   When Unicode normalization fails.
   */
  public static function key(string $email): string {
    $nfc = \Normalizer::normalize($email, \Normalizer::FORM_C);
    if ($nfc === FALSE) {
      throw new \InvalidArgumentException('Invalid email encoding.');
    }
    return mb_strtolower(trim($nfc));
  }

  /**
   * Returns a Gmail review hint, never a lookup key.
   *
   * After key(), Gmail and Googlemail domains strip dots from the
   * local-part and rewrite the domain to gmail.com. Plus-tags remain.
   * Other domains return NULL.
   *
   * @param string $email
   *   Untrusted mailbox address.
   *
   * @return string|null
   *   Rewritten Gmail address, or NULL when the domain is not Gmail.
   *
   * @throws \InvalidArgumentException
   *   When Unicode normalization fails.
   */
  public static function hint(string $email): ?string {
    $parts = explode('@', self::key($email));
    if (count($parts) !== 2) {
      return NULL;
    }
    if (!in_array($parts[1], ['gmail.com', 'googlemail.com'], TRUE)) {
      return NULL;
    }
    return str_replace('.', '', $parts[0]) . '@gmail.com';
  }

  /**
   * Classifies a mailbox as a shared role mailbox or a person mailbox.
   *
   * The mailbox_kind value is person versus role mailbox, not person
   * versus organization.
   *
   * @param string $email
   *   Untrusted mailbox address.
   *
   * @return string
   *   'role' for the bounded local-part list, otherwise 'person'.
   *
   * @throws \InvalidArgumentException
   *   When Unicode normalization fails.
   */
  public static function kind(string $email): string {
    $local = explode('@', self::key($email))[0];
    return in_array($local, self::ROLE_LOCALS, TRUE) ? 'role' : 'person';
  }

}
