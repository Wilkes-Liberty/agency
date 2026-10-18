<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\smtp\Plugin\Mail\SMTPMailSystem;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Freezes normal mail-hook output and qualifies the existing SMTP transport.
 */
final class InvitationMail implements InvitationMailInterface {

  public function __construct(
    private readonly MailManagerInterface $manager,
    private readonly ConfigFactoryInterface $config,
    private readonly DeliveryConfiguration $configuration,
    private readonly SmtpHandoffFactory $handoff,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function prepare(array $content): array {
    $settings = $this->configuration->read();
    $sender = $settings['sender'] ?? [];
    if (!filter_var($sender['email'] ?? '', FILTER_VALIDATE_EMAIL)
      || !filter_var($sender['reply_to'] ?? '', FILTER_VALIDATE_EMAIL)
      || !is_string($sender['name'] ?? NULL) || $sender['name'] === ''
      || preg_match('/[\r\n]/', implode('', $sender))) {
      throw new Refusal('delivery_transport_unavailable');
    }
    $plugin_id = $this->pluginId();
    if (!in_array($plugin_id, ['SMTPMailSystem', 'php_mail', 'test_mail_collector'], TRUE)) {
      throw new Refusal('delivery_transport_unavailable');
    }
    $message = $this->manager->mail('agency_host', 'invitation', $content['recipient'], 'en',
      ['content' => $content, 'sender' => $sender], $sender['reply_to'], FALSE);
    $message['subject'] = PlainTextOutput::renderFromHtml($message['subject']);
    $plugin = $this->manager->getInstance(['module' => 'agency_host', 'key' => 'invitation']);
    if (count($message['headers']) !== count(array_change_key_case($message['headers'], CASE_LOWER))) {
      throw new Refusal('delivery_envelope_changed');
    }
    $message['headers'] = array_change_key_case($message['headers'], CASE_LOWER);
    $this->validateMessage($message, $content['recipient'], $sender);
    if ($settings['mode'] === 'smtp' && get_class($plugin) !== SMTPMailSystem::class) {
      throw new Refusal('delivery_transport_unavailable');
    }
    $routing = $settings['mode'] === 'smtp' ? $this->smtp($settings, $sender, $plugin_id) : ['mode' => 'capture'];
    return array_replace($content, [
      'recipient' => $message['to'],
      'subject' => $message['subject'],
      'body' => $message['body'],
      'from' => $sender['email'],
      'from_name' => $sender['name'],
      'reply_to' => $sender['reply_to'],
      'plugin' => $plugin_id,
      'plugin_class' => get_class($plugin),
      'routing' => $routing,
      'send' => $message['send'],
      'message' => $message,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $envelope): string {
    $settings = $this->configuration->read();
    if ($settings['mode'] === 'capture') {
      // The caller durably captured this exact encrypted envelope at approval.
      return 'captured';
    }
    $configured = $this->manager->getInstance(['module' => 'agency_host', 'key' => 'invitation']);
    $routing = $this->smtp($settings, $settings['sender'] ?? [], $this->pluginId());
    if ($routing !== $envelope['routing'] || $this->pluginId() !== $envelope['plugin'] || get_class($configured) !== $envelope['plugin_class']) {
      throw new Refusal('delivery_transport_unavailable');
    }
    $plugin = $this->handoff->create();
    // No second mail_alter invocation can change the approved message.
    return $plugin->mail($envelope['message']) === TRUE ? 'accepted' : 'unknown';
  }

  /**
   * Uses the same configured precedence as the installed MailManager.
   */
  private function pluginId(): string {
    $interfaces = $this->config->get('system.mail')->get('interface');
    return $interfaces['agency_host'] ?? $interfaces['chronicle_agency_invitation'] ?? $interfaces['default'] ?? '';
  }

  /**
   * Refuses hidden recipients, attachments, header injection and sender changes.
   */
  private function validateMessage(array $message, string $recipient, array $sender): void {
    $headers = array_change_key_case($message['headers'], CASE_LOWER);
    $allowed = [
      'mime-version', 'content-type', 'content-transfer-encoding', 'x-mailer',
      'from', 'sender', 'return-path', 'reply-to', 'content-language',
    ];
    if ($message['to'] !== $recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
      || $message['from'] !== $sender['email'] || $message['reply-to'] !== $sender['reply_to']
      || $message['langcode'] !== 'en' || !is_string($message['body']) || strlen($message['body']) > 16384
      || !is_string($message['subject']) || strlen($message['subject']) > 255
      || array_diff(array_keys($headers), $allowed)
      || array_diff(array_keys($message['params']), ['from_name', 'from_mail'])
      || ($message['params']['from_name'] ?? NULL) !== $sender['name']
      || ($message['params']['from_mail'] ?? NULL) !== $sender['email']
      || ($headers['from'] ?? NULL) !== $sender['email']
      || ($headers['sender'] ?? NULL) !== $sender['email']
      || ($headers['return-path'] ?? NULL) !== $sender['email']
      || ($headers['reply-to'] ?? NULL) !== $sender['reply_to']
      || ($headers['content-type'] ?? NULL) !== 'text/plain; charset=utf-8'
      || (isset($headers['content-language']) && $headers['content-language'] !== 'en')
      || preg_match('/[\r\n]/', $message['subject'])) {
      throw new Refusal('delivery_envelope_changed');
    }
    foreach ($headers as $value) {
      if (!is_string($value) || preg_match('/[\r\n]/', $value)) {
        throw new Refusal('delivery_envelope_changed');
      }
    }
  }

  /**
   * Pins effective non-secret routing and explicit SMTP sender precedence.
   */
  private function smtp(array $settings, array $sender, string $plugin): array {
    $config = $this->config->get('smtp.settings');
    $routing = ['handoff_class' => PrivateSmtp::class];
    foreach (['smtp_on', 'smtp_host', 'smtp_hostbackup', 'smtp_port', 'smtp_protocol', 'smtp_autotls',
      'smtp_timeout', 'smtp_from', 'smtp_fromname', 'smtp_client_hostname', 'smtp_client_helo',
      'smtp_allowhtml', 'smtp_reroute_address', 'smtp_debugging', 'smtp_keepalive', 'smtp_queue',
    ] as $key) {
      $routing[$key] = $config->get($key);
    }
    if ($plugin !== 'SMTPMailSystem' || ($settings['deployment_gate'] ?? FALSE) !== TRUE
      || !$routing['smtp_on'] || !is_string($routing['smtp_host'])
      || !preg_match('/^[a-zA-Z0-9.-]+$/D', $routing['smtp_host'])
      || $routing['smtp_hostbackup'] !== '' || !in_array($routing['smtp_protocol'], ['ssl', 'tls'], TRUE)
      || !is_numeric($routing['smtp_port']) || (int) $routing['smtp_port'] < 1 || (int) $routing['smtp_port'] > 65535
      || !is_numeric($routing['smtp_timeout']) || (int) $routing['smtp_timeout'] < 1 || (int) $routing['smtp_timeout'] > 30
      || $routing['smtp_from'] !== ($sender['email'] ?? NULL) || $routing['smtp_fromname'] !== ($sender['name'] ?? NULL)
      || $routing['smtp_allowhtml'] || $routing['smtp_reroute_address'] !== '' || $routing['smtp_debugging']
      || $routing['smtp_keepalive'] || $routing['smtp_queue']
      || !hash_equals(DeliveryConfiguration::fingerprint($routing), $settings['smtp_qualification'] ?? '')) {
      throw new Refusal('delivery_transport_unavailable');
    }
    // The qualified plugin creates PHPMailer with default certificate checks;
    // there is no caller-controlled SMTPOptions or alternative transport here.
    return $routing;
  }

}
