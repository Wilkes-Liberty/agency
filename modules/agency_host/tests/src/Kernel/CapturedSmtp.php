<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use PHPMailer\PHPMailer\PHPMailer;
use Drupal\agency_host\Delivery\PrivateSmtp;

/**
 * Observes the real plugin's final PHPMailer envelope without opening a socket.
 */
final class CapturedSmtp extends PrivateSmtp {

  /**
   * Synthetic captured envelopes; no live credentials or recipients.
   */
  public array $captured = [];

  /**
   * Optional no-network provider failure for the real parent mail path.
   */
  public ?PHPMailer $failureMailer = NULL;

  /**
   * {@inheritdoc}
   */
  protected function getMailer() {
    return $this->failureMailer ?? parent::getMailer();
  }

  /**
   * {@inheritdoc}
   */
  public function smtpMailerSend(array $mailerArr) {
    if ($this->failureMailer !== NULL) {
      return parent::smtpMailerSend($mailerArr);
    }
    $mailer = $mailerArr['mailer'];
    $mailer->preSend();
    $this->captured[] = [
      'from' => $mailer->From,
      'name' => $mailer->FromName,
      'sender' => $mailer->Sender,
      'to' => $mailer->getToAddresses(),
      'reply' => $mailer->getReplyToAddresses(),
      'cc' => $mailer->getCcAddresses(),
      'bcc' => $mailer->getBccAddresses(),
      'attachments' => $mailer->getAttachments(),
      'body' => $mailer->Body,
      'subject' => $mailer->Subject,
      'host' => $mailer->Host,
      'secure' => $mailer->SMTPSecure,
      'options' => $mailer->SMTPOptions,
    ];
    return TRUE;
  }

}
