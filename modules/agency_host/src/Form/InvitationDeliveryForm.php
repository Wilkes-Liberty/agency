<?php

declare(strict_types=1);

namespace Drupal\agency_host\Form;

use Drupal\agency_host\Console\ConsoleFieldInventory;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Controller\DiscoveryConsoleController;
use Drupal\agency_host\Delivery\InvitationDelivery;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reviews one immutable message; Form API retains responsibility for CSRF.
 */
final class InvitationDeliveryForm extends FormBase {

  public function __construct(private readonly InvitationDelivery $delivery) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('agency_host.invitation_delivery'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agency_host_invitation_delivery';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $delivery = ''): array {
    // Neither the raw token nor formatted body may enter persistent form cache.
    $form_state->disableCache();
    $form_state->set('delivery_id', $delivery);
    $form['#cache']['max-age'] = 0;
    $form['#after_build'][] = [ConsoleFieldInventory::class, 'check'];
    $form['#attributes'] = ['class' => ['agency-console'], 'autocomplete' => 'off'];
    $form['#attached']['library'][] = 'agency_host/console';
    $form['return'] = [
      '#type' => 'link',
      '#title' => $this->t('Return to conversations'),
      '#url' => Url::fromRoute('agency_host.console'),
    ];
    try {
      $support = \Drupal::service('agency_staff.policy')->supportContact();
      $form['support'] = $this->support($support);
      $preview = $this->delivery->preview($this->getRequest(), $delivery);
    }
    catch (Refusal) {
      // Keep an observed result, but never invent a route or expose a preview.
      $preview = ['outcome' => 'refused', 'reason' => 'policy_unavailable'];
      $form['support_unavailable'] = DiscoveryConsoleController::paragraph('Agency support information could not be verified. Return to conversations and ask the responsible coordinator to restore the support route.');
    }
    $result = $form_state->get('delivery_result') ?? $preview;
    if (($result['outcome'] ?? '') !== 'available') {
      $form['outcome'] = [
        '#type' => 'container',
        '#attributes' => ['role' => 'status', 'tabindex' => '-1', 'data-agency-focus' => 'true'],
        'text' => DiscoveryConsoleController::paragraph($this->outcome($result)),
      ];
      return $form;
    }
    $item = $preview['delivery'];
    $return_command = $this->getRequest()->query->get('return');
    $return_review = $this->getRequest()->query->get('return_review');
    $return_url = in_array($return_command, ['issue', 'resend'], TRUE) && is_string($return_review) && preg_match('/^[a-f0-9]{48}$/D', $return_review)
      ? Url::fromRoute('agency_host.console_command', [
        'relationship' => $item['relationship'],
        'command' => $return_command,
      ], ['fragment' => 'delivery-' . $delivery, 'query' => ['review' => $return_review]])
      : Url::fromRoute('agency_host.console_detail', ['relationship' => $item['relationship']], ['fragment' => 'delivery-' . $delivery]);
    $form['intro'] = DiscoveryConsoleController::paragraph('Invitation captured; not approved. Review the complete message before authorizing one attempt. Signing in and accepting remain separate.');
    $labels = [
      'recipient' => 'To',
      'from' => 'From',
      'from_name' => 'Sender name',
      'reply_to' => 'Reply to',
      'subject' => 'Subject',
    ];
    foreach ($labels as $key => $label) {
      $value = $item[$key] ?? $item['envelope'][$key] ?? '';
      $form[$key] = DiscoveryConsoleController::paragraph($label . ': ' . $value);
    }
    $form['transport'] = DiscoveryConsoleController::paragraph('Mail plugin: ' . $item['envelope']['plugin']);
    foreach ($item['envelope']['message']['headers'] ?? [] as $name => $value) {
      $form['headers'][$name] = DiscoveryConsoleController::paragraph($name . ': ' . $value);
    }
    $form['body'] = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#attributes' => ['class' => ['agency-console__mail']],
      'text' => ['#plain_text' => $item['body']],
    ];
    $form['preview_fingerprint'] = [
      '#type' => 'hidden',
      '#default_value' => $item['fingerprint'],
      '#agency_field_purpose' => 'delivery.preview_fingerprint',
    ];
    $form['approval_key'] = [
      '#type' => 'hidden',
      '#default_value' => bin2hex(random_bytes(24)),
      '#agency_field_purpose' => 'delivery.approval_key',
    ];
    $form['approve'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I approve this exact recipient and message for one attempt.'),
      '#default_value' => FALSE,
      '#required' => TRUE,
      '#agency_field_purpose' => 'delivery.approve',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['authorize'] = [
      '#type' => 'submit',
      '#name' => 'authorize_delivery',
      '#value' => $this->t('Authorize one delivery attempt'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel without approving'),
      '#url' => $return_url,
    ];
    return $form;
  }

  /**
   * Requires the explicit button and checkbox, in addition to Core's CSRF token.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    $posted = $this->getRequest()->request;
    if (strlen(json_encode($posted->all(), JSON_THROW_ON_ERROR)) > 16384
      || ($button['#name'] ?? '') !== 'authorize_delivery'
      || $posted->get('authorize_delivery') !== (string) $this->t('Authorize one delivery attempt')
      || !$form_state->getValue('approve')
      || !preg_match('/^[a-f0-9]{64}$/D', (string) $form_state->getValue('preview_fingerprint'))
      || !preg_match('/^[a-f0-9]{48}$/D', (string) $form_state->getValue('approval_key'))) {
      $form_state->setErrorByName('approve', $this->t('Review the message, select the approval, then authorize one attempt.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->delivery->dispatch($this->getRequest(), $form_state->get('delivery_id'),
      (string) $form_state->getValue('preview_fingerprint'), (string) $form_state->getValue('approval_key'));
    $form_state->set('delivery_result', $result)->setRebuild();
  }

  /**
   * Links only safe known contact kinds without interpreting arbitrary schemes.
   */
  private function support(array $support): array {
    $value = $support['value'];
    $uri = NULL;
    if ($support['kind'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
      $uri = 'mailto:' . rawurlencode($value);
    }
    elseif ($support['kind'] === 'url' && filter_var($value, FILTER_VALIDATE_URL)
      && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], TRUE)
      && parse_url($value, PHP_URL_USER) === NULL && parse_url($value, PHP_URL_PASS) === NULL) {
      $uri = $value;
    }
    elseif ($support['kind'] === 'phone' && preg_match('/^\+?[0-9][0-9() .-]{1,30}[0-9]$/D', $value)) {
      $uri = 'tel:' . preg_replace('/[() .-]/', '', $value);
    }
    $label = 'Agency support (' . $support['kind'] . '): ' . $value;
    return $uri === NULL ? DiscoveryConsoleController::paragraph($label) : [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromUri($uri),
    ];
  }

  /**
   * Names minimized historical states without claiming participant acceptance.
   */
  public static function stateLabel(string $state): string {
    return match ($state) {
      'captured' => 'not approved',
      'test_captured' => 'captured for testing; nothing sent',
      'accepted' => 'accepted by transport',
      'attempting', 'unknown' => 'outcome unknown',
      'capture_unavailable' => 'capture unavailable',
      'refused' => 'issuance refused',
      default => 'issuance unresolved',
    };
  }

  /**
   * Separates custody, synthetic capture, handoff and uncertain recovery.
   */
  private function outcome(array $result): string {
    return match ($result['outcome'] ?? '') {
      'test_captured' => 'Approved message captured for testing; nothing sent. The invitation remains separate from delivery and acceptance.',
      'accepted' => 'The mail transport accepted the message. This does not confirm delivery or participant acceptance. Do not send another copy.',
      'unknown', 'attempting' => 'The delivery outcome is unknown. Do not repeat this attempt. Ask the responsible coordinator to review available mail evidence with mail support. Any replacement requires a separate reviewed decision and an explicit new-generation action; earlier mail may already be in flight.',
      'capture_unavailable' => 'The invitation may exist, but its message could not be captured. No mail handoff occurred. Ask the responsible coordinator to review revocation before explicitly creating another generation.',
      'reserved' => 'Invitation issuance is unresolved. No delivery attempt has been recorded. Return to the earlier request and reconcile that exact command; do not create another invitation.',
      'issuance_refused' => 'This invitation request was not committed, and no mail handoff occurred for it. Return to the conversation to review the current state before starting a new request.',
      default => match ($result['reason'] ?? '') {
        'policy_unavailable' => 'Invitation delivery is unavailable. No new delivery can be authorized from this review. Earlier attempts may still need reconciliation.',
        'preview_changed' => 'The message or delivery settings changed. No new attempt was authorized here. Open a fresh review before approving; earlier attempts may still need reconciliation.',
        'delivery_suppressed', 'contact_refused' => 'Current contact or suppression rules prevent delivery. No new attempt was authorized here. Ask the responsible coordinator to review current permission and reconcile any earlier attempt; do not retry automatically.',
        'delivery_disabled', 'delivery_transport_unavailable' => 'Invitation delivery is unavailable. No new attempt was authorized here. Ask the responsible coordinator to check delivery readiness and reconcile any earlier attempt.',
        default => 'This invitation review is unavailable. No new delivery was authorized here. Return to conversations or contact agency support. Earlier requests may still need reconciliation.',
      },
    };
  }

}
