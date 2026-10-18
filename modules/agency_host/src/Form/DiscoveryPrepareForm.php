<?php

declare(strict_types=1);

namespace Drupal\agency_host\Form;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Controller\DiscoveryConsoleController;
use Drupal\Core\Form\FormStateInterface;

/**
 * Separates read-only lookup, explicit identity confirmation and preparation.
 */
final class DiscoveryPrepareForm extends ConsoleFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agency_host_console_prepare';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    try {
      return $this->buildConsoleForm($form, $form_state);
    }
    catch (Refusal $error) {
      $form = $this->shell($form, $form_state);
      $form['error'] = $this->message($this->copy->error(['reason' => $error->reason]));
      return $form;
    }
  }

  /**
   * Builds only after strict session-state reads succeed.
   */
  private function buildConsoleForm(array $form, FormStateInterface $form_state): array {
    $form = $this->shell($form, $form_state);
    try {
      $this->restoreReview($form, $form_state, 'prepare', 'prepare');
    }
    catch (Refusal $error) {
      if ($error->reason === 'review_expired') {
        return $this->expiredForm($form, $form_state, 'prepare', 'prepare');
      }
      $form['error'] = $this->message($this->copy->error(['reason' => $error->reason]));
      return $form;
    }
    $intent = $form_state->get('intent');
    if ($intent && !$form_state->get('reconfirming')) {
      $form_state->set('selection', $intent['selection'])->set('confirmed', TRUE);
    }

    $result = $form_state->get('result');
    if (($result['reason'] ?? '') === 'review_capacity') {
      return $this->capacityForm($form);
    }
    if (($result['outcome'] ?? '') === 'committed') {
      $queue = $this->application->queue($this->getRequest());
      $saved_name = NULL;
      foreach ($queue['contexts'] ?? [] as $row) {
        if ($row['id'] === ($result['receipt']['relationship_id'] ?? NULL)) {
          $saved_name = $row['display_name'];
          break;
        }
      }
      $this->resultHeading($form, $result, []);
      $form_state->disableCache();
      $form['result'] = $this->message($saved_name !== NULL
        ? $this->copy->text('prepare.receipt', ['name' => $saved_name])
        : $this->copy->text('prepare.receiptGeneric'));
      if ($saved_name !== NULL) {
        $form['saved_deadline'] = DiscoveryConsoleController::paragraph($this->copy->text('queue.colExpectedBy') . ': ' . \Drupal::service('date.formatter')->format($intent['input']['next_action']['expected_by'], 'custom', 'Y-m-d H:i T'));
      }
      $this->acknowledgement($form, $result);
      return $form;
    }
    try {
      $agency = $this->policy->name();
    }
    catch (Refusal) {
      $form['error'] = $this->message($this->copy->error(['reason' => 'policy_unavailable']));
      return $form;
    }
    if ($result) {
      if ($intent) {
        $this->resultHeading($form, $result, ['name' => $intent['input']['display_name']]);
      }
      $form['result'] = $this->message(($result['reason'] ?? '') === 'selection_lapsed'
        ? $this->copy->text('prepare.selectionLapsed') . ' ' . $this->copy->text('errors.checkFirst')
        : $this->copy->error($result));
    }
    if (isset($form['result'])) {
      $form['result']['#attributes']['data-agency-focus'] = 'true';
    }
    $form['intro'] = $this->message($this->copy->text('prepare.intro'));
    $form['display_name'] = $this->field('display_name', $this->copy->text('prepare.nameLabel'), 'textfield', 255);
    $form['display_name']['#description'] = $this->copy->text('prepare.nameHelp');
    $form['email'] = $this->field('email', $this->copy->text('prepare.emailLabel'), 'email', 255);
    $form['email']['#description'] = $this->copy->text('prepare.emailHelp');
    $form['email']['#attributes']['data-agency-selection-email'] = 'true';
    $form['lookup_help'] = DiscoveryConsoleController::paragraph($this->copy->text('lookup.help'));
    $form['find'] = [
      '#type' => 'submit',
      '#name' => 'find',
      '#value' => $this->copy->text('lookup.button'),
      '#submit' => ['::submitForm'],
      '#limit_validation_errors' => [
    [
      'email',
    ],
      ],
    ];
    $selection = $form_state->get('selection');
    $status = $form_state->get('lookup_status');
    if ($status) {
      $form['lookup_status'] = $this->message($this->copy->text($status));
      $form['lookup_status']['#attributes']['data-agency-focus'] = 'true';
    }
    if ($selection) {
      $form['selection'] = ['#type' => 'container', '#attributes' => ['data-agency-selection' => 'true']];
      $form['selection']['heading'] = DiscoveryConsoleController::paragraph($this->copy->text('lookup.foundHeading'), 'h2');
      $form['selection']['intro'] = DiscoveryConsoleController::paragraph($this->copy->text('lookup.foundIntro'));
      $form['selection']['name'] = DiscoveryConsoleController::paragraph($this->copy->text('lookup.rowName') . ': ' . ($selection['name'] ?: $this->copy->text('lookup.noName')));
      $form['selection']['email'] = DiscoveryConsoleController::paragraph($this->copy->text('lookup.rowEmail') . ': ' . $selection['email']);
      $form['selection']['consequence'] = DiscoveryConsoleController::paragraph($this->copy->text('lookup.consequence'));
      if (!$form_state->get('confirmed')) {
        $form['selection']['confirm'] = [
          '#type' => 'submit',
          '#name' => 'confirm',
          '#value' => $this->copy->text('lookup.confirm'),
          '#submit' => ['::submitForm'],
          '#limit_validation_errors' => [
        [
          'email',
        ],
          ],
        ];
      }
      $form['selection']['reject'] = [
        '#type' => 'submit',
        '#name' => 'reject',
        '#value' => $this->copy->text('lookup.reject'),
        '#submit' => ['::submitForm'],
        '#limit_validation_errors' => [
      [
        'email',
      ],
        ],
      ];
    }
    $form['selection_cleared'] = $this->message($this->copy->text('lookup.cleared'));
    $form['selection_cleared']['#attributes']['data-agency-selection-cleared'] = 'true';
    $form['selection_cleared']['#attributes']['hidden'] = TRUE;
    $form['owner_contact'] = $this->field('owner.contact', $this->copy->text('prepare.ownerContactLabel'), 'textfield', 255);
    $form['owner_contact']['#description'] = $this->copy->text('prepare.ownerContactHelp');
    $form['owner_note'] = DiscoveryConsoleController::paragraph($this->copy->text('prepare.ownerNote'));
    $this->actionFields($form, 'prepare', ['agencyName' => $agency], ['agency', 'participant']);
    $form['language'] = DiscoveryConsoleController::paragraph($this->copy->text('prepare.languageNote'));
    if (!$form_state->get('confirmed')) {
      $form['needs_confirm'] = $this->message($this->copy->text('prepare.needsConfirm'));
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['prepare'] = [
      '#type' => 'submit',
      '#name' => 'prepare',
      '#value' => $this->copy->text(is_array($form_state->get('intent')) ? 'recovery.retryExact' : 'prepare.submit'),
      '#disabled' => !$form_state->get('confirmed'),
      '#attributes' => [
        'data-agency-prepare' => 'true',
      ],
    ];
    if ($intent) {
      if (!$form_state->get('reconfirming') && ($intent['selection']['expires'] ?? 0) > \Drupal::time()->getCurrentTime()
        && ($result['reason'] ?? '') !== 'selection_lapsed') {
        unset($form['find'], $form['selection']['confirm'], $form['selection']['reject']);
      }
      else {
        unset($form['selection']['reject']);
      }
      $input = $intent['input'];
      $this->draftFields($form, $form_state, $input['next_action'] + [
        'display_name' => $input['display_name'],
        'email' => $input['contact']['value'],
        'owner_contact' => $input['owner_contact']['value'],
      ], TRUE);
      $form['retry_help'] = $this->message($this->copy->text('recovery.prepareRetryHelp'));
      if (!empty($result['unconfirmed'])) {
        $form['unconfirmed'] = $this->message($this->copy->text('recovery.prepareUnconfirmedBody', [
          'name' => $input['display_name'],
        ]));
      }
      $this->acknowledgement($form, $result);
    }
    elseif ($draft = $form_state->get('draft')) {
      $this->draftFields($form, $form_state, $draft['next_action'] + [
        'display_name' => $draft['display_name'],
        'email' => $draft['contact']['value'],
        'owner_contact' => $draft['owner_contact']['value'],
      ], FALSE);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->act($form, $form_state);
    }
    catch (Refusal $error) {
      $context = $form_state->get('relationship') ?: 'prepare';
      try {
        $pending = $this->application->pending($this->getRequest(), $context);
      }
      catch (Refusal) {
        $pending = ['unconfirmed' => TRUE];
      }
      $form_state->set('result', [
        'outcome' => 'refused',
        'reason' => $error->reason,
        'unconfirmed' => !empty($pending['unconfirmed']),
      ])->setRebuild();
    }
  }

  /**
   * Processes an explicit protected form action without exception detail leaks.
   */
  private function act(array &$form, FormStateInterface $form_state): void {
    $this->requireExplicitAction($form_state, $form_state->getTriggeringElement()['#name'] ?? '');
    if ($this->releaseExpired($form_state)) {
      return;
    }
    $button = $form_state->getTriggeringElement()['#name'];
    $intent = $form_state->get('intent');
    if ($button === 'acknowledge' && $intent) {
      $this->requireExplicitAction($form_state, 'acknowledge');
      $this->reviews->acknowledge($this->getRequest(), $form_state->get('review_nonce'), 'prepare', 'prepare');
      if (($form_state->get('result')['outcome'] ?? '') === 'committed') {
        $form_state->setRedirect('agency_host.console');
      }
      else {
        $form_state->set('draft', $intent['input'])->set('intent', NULL)->set('review_nonce', NULL)->set('result', NULL)->set('selection', NULL)->set('confirmed', FALSE)->setRebuild();
        $this->getRequest()->request->remove('review_nonce');
        $form_state->setUserInput([]);
      }
      return;
    }
    if ($intent && !in_array($button, ['prepare', 'find', 'confirm'], TRUE)) {
      $form_state->set('result', [
        'outcome' => 'refused',
        'reason' => 'prior_unconfirmed',
        'unconfirmed' => TRUE,
      ])->setRebuild();
      return;
    }

    $email = $intent['input']['contact']['value'] ?? trim((string) $form_state->getValue('email'));
    $selection = $form_state->get('selection');
    if ($selection && $selection['email'] !== $email) {
      $form_state->set('selection', NULL)->set('confirmed', FALSE)->set('lookup_status', 'lookup.cleared');
      $selection = NULL;
    }
    $application = $this->application;
    $lookup = \Drupal::service('agency_host.console_selection');
    if ($button === 'find') {
      $result = $application->run($this->getRequest(), fn(): array => $lookup->find($email));
      if ($intent) {
        foreach (['uid', 'subject', 'issuer', 'email'] as $field) {
          if (($result['selection'][$field] ?? NULL) !== $intent['selection'][$field]) {
            $result = ['outcome' => 'none'];
            break;
          }
        }
        $form_state->set('reconfirming', TRUE);
      }

      $form_state->set('selection', $result['selection'] ?? NULL)->set('confirmed', FALSE)->set('result', NULL);
      $form_state->set('lookup_status', ($result['outcome'] ?? '') === 'available' ? NULL
        : (($result['reason'] ?? '') === 'identity_unavailable' ? 'lookup.unavailable' : 'lookup.none'));
      if (in_array($result['reason'] ?? '', ['contact_refused', 'rate_limited', 'not_found'], TRUE)) {
        $form_state->set('result', $result)->set('lookup_status', NULL);
      }
    }
    elseif ($button === 'confirm' && $selection) {
      $result = $application->run($this->getRequest(), fn(): array => $lookup->apply($selection, $email, static fn(): array => [
        'outcome' => 'available',
      ]));
      if ($intent && ($result['outcome'] ?? '') === 'available') {
        try {
          $renewed = $this->reviews->reconfirm($this->getRequest(), $intent['review_nonce'], $selection);
          $form_state->set('intent', $renewed + [
            'review_nonce' => $intent['review_nonce'],
            'command' => 'prepare',
          ])->set('reconfirming', FALSE);
        }
        catch (Refusal) {
          $result = ['outcome' => 'refused', 'reason' => 'selection_lapsed'];
        }
      }
      $form_state->set('confirmed', ($result['outcome'] ?? '') === 'available');
      $form_state->set('lookup_status', $form_state->get('confirmed') ? 'lookup.confirmed' : NULL);
      $form_state->set('result', $form_state->get('confirmed') ? NULL : $result);
    }
    elseif ($button === 'reject') {
      $form_state->set('selection', NULL)->set('confirmed', FALSE)->set('lookup_status', 'lookup.rejected');
    }
    elseif ($button === 'prepare') {
      if (!$selection || !$form_state->get('confirmed')) {
        $form_state->set('result', ['outcome' => 'refused', 'reason' => 'selection_lapsed'])->setRebuild();
        return;
      }
      $intent = $form_state->get('intent');
      if (!$intent) {
        try {
          $intent = [
            'key' => bin2hex(random_bytes(24)),
            'selection' => $selection,
            'input' => [
              'display_name' => (string) $form_state->getValue('display_name'),
              'contact' => ['kind' => 'email', 'value' => $email],
              'owner_contact' => $this->contact((string) $form_state->getValue('owner_contact')),
              'next_action' => $this->actionInput($form_state, (string) $form_state->getValue('side')),
            ],
          ];
          $this->captureReview($form_state, 'prepare', 'prepare', $intent, [
            'display_name' => $intent['input']['display_name'],
            'agency_name' => $this->policy->name(),
          ]);
          $intent = $form_state->get('intent');
        }
        catch (Refusal $error) {
          $form_state->set('result', ['outcome' => 'refused', 'reason' => $error->reason])->setRebuild();
          return;
        }
      }
      $nonce = $intent['review_nonce'];
      $record = $this->reviews->read($this->getRequest(), $nonce, 'prepare', 'prepare');
      $intent = $record['intent'] + ['review_nonce' => $nonce, 'command' => 'prepare'];
      $form_state->set('intent', $intent);
      $this->reviews->attempted($this->getRequest(), $intent['review_nonce']);
      $delivery = \Drupal::service('agency_host.invitation_delivery');
      $result = $application->intent($this->getRequest(), 'prepare', $intent, fn(): array => $delivery->enabled()
        ? $delivery->prepare($this->getRequest(), $intent['selection'], $intent['input'], $intent['key'])
        : $lookup->apply($intent['selection'], $intent['input']['contact']['value'], fn(): array => \Drupal::service('agency_staff.commands')->startDiscovery($intent['input'], $intent['key'])));
      $result = $this->reviews->result($this->getRequest(), $intent['review_nonce'], $result);
      $form_state->set('result', $result);
    }
    $form_state->setRebuild();
  }

}
