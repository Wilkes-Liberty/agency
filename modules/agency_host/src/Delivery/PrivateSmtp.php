<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\smtp\Plugin\Mail\SMTPMailSystem;

/**
 * Keeps provider-controlled mail failure text out of application logs.
 *
 * The qualified parent still constructs and formats the SMTP envelope. This
 * handoff never invokes its general-purpose helper, which logs ErrorInfo.
 */
class PrivateSmtp extends SMTPMailSystem {

  /**
   * {@inheritdoc}
   */
  public function smtpMailerSend(array $mailerArr) {
    $mailer = $mailerArr['mailer'];
    try {
      return $mailer->send() === TRUE;
    }
    catch (\Throwable) {
      // An exception after entering Send is uncertain, never safe to retry.
      return FALSE;
    }
    finally {
      $mailer->smtpClose();
    }
  }

}
