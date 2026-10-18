<?php

declare(strict_types=1);

namespace Drupal\agency_host\Form;

use Drupal\agency_host\Delivery\InvitationDelivery;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Controller\DiscoveryConsoleController;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Executes one explicit coordinator action through protected Form API state.
 */
final class DiscoveryCommandForm extends ConsoleFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agency_host_console_command';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $relationship = '', string $command = ''): array {
    try {
      return $this->buildConsoleForm($form, $form_state, $relationship, $command);
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
  private function buildConsoleForm(array $form, FormStateInterface $form_state, string $relationship = '', string $command = ''): array {
    $form = $this->shell($form, $form_state);
    $form_state->set('relationship', $relationship)->set('command', $command);
    if ($command === 'resend') {
      $warning = \Drupal::service('agency_host.invitation_delivery')->replacementWarning($this->getRequest(), $relationship);
      if (!empty($warning['prior_handoff'])) {
        $form['prior_delivery'] = $this->message('An earlier message may already be in flight. Do not repeat that attempt. Before replacing its invitation, the responsible coordinator must review available mail evidence and make a separate replacement decision. A new link will not recall earlier mail.');
      }
    }
    $queue = $this->application->queue($this->getRequest());
    $row = NULL;
    foreach ($queue['contexts'] ?? [] as $candidate) {
      if ($candidate['id'] === $relationship) {
        $row = $candidate;
        break;
      }
    }
    if (!$row) {
      // Own-session outcome metadata does not authorize the old projection.
      $request = $this->getRequest();
      $nonce = $request->request->get('review_nonce') ?? $form_state->get('review_nonce')
        ?? ($request->isMethodSafe() ? $request->query->get('review') : NULL);
      if (($queue['outcome'] ?? '') === 'available' && is_string($nonce)) {
        $record = $this->reviews->listing($request)[$nonce] ?? NULL;
        if ($record && $record['context'] === $relationship && $record['command'] === $command) {
          $form_state->disableCache();
          if ($record['expires'] <= \Drupal::time()->getCurrentTime()) {
            $form['result_heading'] = DiscoveryConsoleController::paragraph($this->copy->text('recovery.expiredHeading'), 'h2');
            $form['result'] = $this->message($this->copy->text('recovery.expiredBodyGeneric'));
          }
          elseif ($record['attempted']) {
            $result = $record['result'] ?: ['outcome' => 'uncertain', 'unconfirmed' => TRUE];
            // No relationship-specific conflict wording or retained draft.
            $key = !empty($result['unconfirmed']) || ($result['outcome'] ?? '') === 'uncertain'
              ? 'unconfirmedHeading' : (($result['outcome'] ?? '') === 'committed' ? 'confirmedSaved' : 'confirmedNotSaved');
            $form['result_heading'] = DiscoveryConsoleController::paragraph($this->copy->text('recovery.' . $key), 'h2');
            if ($key === 'unconfirmedHeading') {
              $form['result'] = $this->message($this->copy->text('recovery.accessLost'));
            }
          }
          else {
            $form['result'] = $this->message($this->copy->text('recovery.unavailableGeneric'));
          }
          if (isset($form['result_heading'])) {
            $form['outcome'] = [
              '#type' => 'container',
              '#attributes' => [
                'role' => 'status',
                'tabindex' => '-1',
                'data-agency-focus' => 'true',
              ],
              'heading' => $form['result_heading'],
            ];
            unset($form['result_heading']);
          }
          $form['guard_scope'] = $this->message($this->copy->text('recovery.guardScope'));
          $form['return_result'] = [
            '#type' => 'link',
            '#title' => $this->copy->text('recovery.capacityReturn'),
            '#url' => Url::fromRoute('agency_host.console_held', [], ['query' => ['review' => $nonce]]),
          ];
          return $form;
        }
      }
      $pending = $this->application->pending($this->getRequest(), $relationship);
      $form['error'] = $this->message(!empty($pending['unconfirmed']) || $nonce !== NULL
        ? $this->copy->text('recovery.accessLost')
        : $this->copy->error(($queue['outcome'] ?? '') === 'available' ? ['reason' => 'not_found'] : $queue));
      $form['error']['#attributes']['data-agency-focus'] = 'true';
      return $form;
    }
    if ($blocked = $this->pendingForm($form, $relationship, $command)) {
      return $blocked;
    }
    try {
      $this->restoreReview($form, $form_state, $relationship, $command);
    }
    catch (Refusal $error) {
      if ($error->reason === 'review_expired') {
        return $this->expiredForm($form, $form_state, $relationship, $command, $row['display_name']);
      }
      $form['error'] = $this->message($this->copy->error(['reason' => $error->reason]));
      return $form;
    }
    if (!is_array($form_state->get('intent')) && $row && $this->getRequest()->isMethodSafe()
      && in_array($command, ['issue', 'resend', 'revokeInvitation', 'resumeRelationship'], TRUE)
      && isset(DiscoveryConsoleController::actions($row)[$command])) {
      try {
        $this->captureReview($form_state, $relationship, $command, [
          'input' => [],
          'key' => bin2hex(random_bytes(24)),
          'version' => $row['version'],
        ], $row);
        $this->restoreReview($form, $form_state, $relationship, $command);
      }
      catch (Refusal $error) {
        if ($error->reason === 'review_capacity') {
          return $this->capacityForm($form);
        }
        $form['error'] = $this->message($this->copy->error(['reason' => $error->reason]));
        return $form;
      }
    }
    $result = $form_state->get('result');
    if (($result['reason'] ?? '') === 'review_capacity') {
      return $this->capacityForm($form);
    }
    if ($result) {
      if (is_array($form_state->get('intent'))) {
        $this->resultHeading($form, $result, ['name' => $form_state->get('display_name')]);
      }
      $form['result'] = $this->message(($result['outcome'] ?? '') === 'committed'
        ? $this->copy->text(($result['receipt']['evidence'] ?? '') === 'recorded' ? 'receipt.recorded' : 'receipt.recordNotDelivered')
        : $this->copy->error($result, $command));
    }
    if (isset($form['result'])) {
      $form['result']['#attributes']['data-agency-focus'] = 'true';
    }
    if (($result['outcome'] ?? '') === 'committed') {
      $form_state->disableCache();
      $intent = $form_state->get('intent');
      $saved_deadline = in_array($command, ['setNextAction', 'pauseRelationship'], TRUE)
        ? ($intent['input']['expected_by'] ?? NULL) : NULL;
      // Computed deadlines are historical only at the committed version.
      if (is_int($result['version'] ?? NULL) && ($row['version'] ?? NULL) === $result['version']) {
        if (in_array($command, ['resumeRelationship', 'moveForward'], TRUE)) {
          $saved_deadline = $row['next_action']['expected_by'] ?? NULL;
        }
      }
      if (is_int($saved_deadline)) {
        $form['saved_deadline'] = DiscoveryConsoleController::paragraph($this->copy->text('queue.colExpectedBy') . ': ' . \Drupal::service('date.formatter')->format($saved_deadline, 'custom', 'Y-m-d H:i T'));
      }
      if (in_array($command, ['issue', 'resend'], TRUE)) {
        $invitation = $form_state->getTemporaryValue('invitation');
        if (is_array($invitation)) {
          $name = ['name' => $form_state->get('display_name')];
          $form['link_status'] = $this->message($this->copy->text('invitation.linkOnce', $name));
          $form['invitation_link'] = [
            '#type' => 'link',
            '#title' => $this->copy->text('invitation.linkLabel'),
            '#url' => Url::fromUri($invitation['url']),
            '#attributes' => [
              'rel' => 'noreferrer',
            ],
          ];
          $date = \Drupal::service('date.formatter')->format($invitation['expires'], 'custom', 'Y-m-d H:i T');
          $form['captured'] = $this->message($this->copy->text($command === 'issue' ? 'invitation.issueReceipt' : 'invitation.resendReceipt', $name + [
            'date' => $date,
          ]));
          $form_state->setTemporaryValue('invitation', NULL);
        }
        else {
          $form['link_status'] = $this->message($this->copy->text('invitation.linkUnavailable'));
        }
      }
      if ($command === 'close') {
        $form['delivery'] = $this->message($this->copy->text('close.receipt', [
          'name' => $form_state->get('display_name'),
        ]));
      }
      if (isset($result['delivery_id']) && $this->currentUser()->hasPermission(InvitationDelivery::PERMISSION)) {
        $form['delivery_review'] = [
          '#type' => 'link',
          '#title' => $this->t('Review invitation delivery'),
          '#url' => Url::fromRoute('agency_host.console_delivery', ['delivery' => $result['delivery_id']], [
            'query' => ['return' => $command, 'return_review' => $form_state->get('review_nonce')],
          ]),
          '#attributes' => ['id' => 'delivery-' . $result['delivery_id']],
        ];
      }
      $this->acknowledgement($form, $result);
      return $form;
    }
    if (!$row && !$form_state->get('intent')) {
      $form['error'] = $this->message($this->copy->error($queue['outcome'] === 'available' ? [
        'reason' => 'not_found',
      ] : $queue));
      return $form;
    }
    if (!$form_state->has('display_name')) {
      if (!isset(DiscoveryConsoleController::actions($row)[$command])) {
        $form['error'] = $this->message($this->copy->error(['reason' => 'not_found']));
        return $form;
      }
      $form_state->set('version', $form_state->get('intent')['version'] ?? $row['version']);
      $form_state->set('display_name', $row['display_name']);
      $form_state->set('agency_name', $row['agency_name']);
      $form_state->set('sides', $row['set_next_action_sides']);
    }
    $form_state->set('relationship', $relationship)->set('command', $command);
    $values = [
      'name' => $form_state->get('display_name'),
      'agencyName' => $form_state->get('agency_name'),
      'ownerName' => $row['owner']['name'] ?? '',
    ];
    $prefix = match ($command) {
      'setNextAction' => 'nextStep', 'pauseRelationship' => 'pause',
      'resumeRelationship' => 'resume', 'moveForward' => 'proposal',
      default => $command,
    };
    if (in_array($command, ['setNextAction', 'pauseRelationship'], TRUE)) {
      $this->actionFields($form, $prefix, $values, $command === 'pauseRelationship' ? ['agency'] : $form_state->get('sides'));
    }
    elseif ($command === 'close') {
      $form['intro'] = $this->message($this->copy->text('close.intro', $values));
      $form['category'] = $this->field('close.category', $this->copy->text('close.reasonLabel'), 'select');
      $form['category']['#options'] = array_column($this->policy->value('close_reasons') ?? [], 'label', 'id');
      $form['message'] = $this->field('close.message', $this->copy->text('close.messageLabel', $values), 'textarea', 2000);
      $form['contact'] = $this->field('close.contact', $this->copy->text('close.contactLabel', $values), 'textfield', 512);
      $form['confirm'] = $this->field('close.confirm', $this->copy->text('close.confirmBody', $values), 'checkbox');
    }
    elseif ($command === 'moveForward') {
      $form['intro'] = $this->message($this->copy->text('proposal.intro', $values));
      $form['target_purpose'] = $this->field('proposal.purpose', $this->copy->text('proposal.purposeLabel'), 'textfield', 255);
      $form['message'] = $this->field('proposal.message', $this->copy->text('proposal.messageLabel', $values), 'textarea', 2000);
    }
    elseif ($command === 'reassign') {
      $form['owner'] = $this->field('owner.uid', $this->copy->text('reassign.ownerLabel'), 'select');
      $form['owner']['#options'] = [];
      $ids = $this->entities->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('status', 1)->sort('name')->execute();
      foreach ($ids as $uid) {
        try {
          $owner = $this->actors->assignee((int) $uid);
          $form['owner']['#options'][$uid] = $owner->getDisplayName();
        }
        catch (Refusal) {
          // Unqualified accounts are not disclosed as eligible owners.
        }
      }
      $form['contact'] = $this->field('owner.contact', $this->copy->text('reassign.contactLabel', $values), 'textfield', 512);
      $form['help'] = $this->message($this->copy->text('reassign.help', $values));
    }
    elseif ($command === 'revokeInvitation') {
      $form['confirm_body'] = $this->message($this->copy->text('invitation.revokeBody', $values));
    }
    elseif (in_array($command, ['issue', 'resend'], TRUE)) {
      $form['policy'] = $this->message($this->copy->text('invitation.validityPolicy') . ' ' . $this->copy->text('prepare.languageNote'));
      if ($command === 'issue' && $form_state->get('await_confirmation')
        && in_array('issue', $row['permitted_actions'], TRUE)) {
        $form['preview_intro'] = $this->message($this->copy->text('invitation.previewIntro', $values));
      }
      if ($command === 'resend') {
        $form['confirm_body'] = $this->message($this->copy->text('invitation.resendBody'));
      }
    }
    elseif ($command === 'resumeRelationship') {
      $form['body'] = $this->message($this->copy->text('resume.body', $values));
    }
    if ($result && !empty($result['unconfirmed'])) {
      $form['unconfirmed'] = $this->message($this->copy->text('recovery.unconfirmedBody', $values));
    }
    $review = $form_state->get($form_state->get('await_confirmation') ? 'confirmation_projection' : 'review');
    if ($form_state->get('await_confirmation')) {
      $form['review_title'] = DiscoveryConsoleController::paragraph($this->copy->text('review.heading'), 'h2');
      $form['review_title']['#attributes'] = ['tabindex' => '-1', 'data-agency-focus' => 'true'];
      $form['review_intro'] = DiscoveryConsoleController::paragraph($this->copy->text('review.intro', $values));
      if ($command !== 'revokeInvitation') {
        $form['review_basis'] = DiscoveryConsoleController::paragraph($this->copy->text('review.basis', $values + [
          'state' => $this->copy->text(DiscoveryConsoleController::stateKey($review), $values),
        ]));
      }
      $form['commit_note'] = DiscoveryConsoleController::paragraph($this->copy->text('review.commitNote'));
    }
    if ($review) {
      $form['review_heading'] = DiscoveryConsoleController::paragraph($this->copy->text('recovery.currentHeading'), 'h2');
      $current_values = ['name' => $review['display_name'], 'agencyName' => $review['agency_name']];
      $next_step = $review['next_action']['summary'] ?? $this->copy->text('queue.noNextStep');
      if (($review['next_action']['code'] ?? '') === 'agency_response') {
        $current_values['date'] = \Drupal::service('date.formatter')->format($review['next_action']['expected_by'], 'custom', 'Y-m-d H:i T');
        $next_step = $this->copy->text('nextStep.currentAgencyResponse', $current_values);
      }
      $form['review'] = DiscoveryConsoleController::paragraph($this->copy->text(DiscoveryConsoleController::stateKey($review), $current_values) . ' — ' . $next_step);
      if (($review['next_action']['code'] ?? '') !== 'agency_response' && is_int($review['next_action']['expected_by'] ?? NULL)) {
        $form['review_deadline'] = DiscoveryConsoleController::paragraph($this->copy->text('queue.colExpectedBy') . ': ' . \Drupal::service('date.formatter')->format($review['next_action']['expected_by'], 'custom', 'Y-m-d H:i T'));
      }
    }
    if ($form_state->get('await_confirmation') && !in_array($command, [
      'issue',
      'resend',
      'revokeInvitation',
      'resumeRelationship',
    ], TRUE)) {
      $form['actions']['edit'] = [
        '#type' => 'submit',
        '#name' => 'edit',
        '#value' => $this->copy->text('review.edit'),
        '#submit' => ['::submitForm'],
        '#limit_validation_errors' => [],
      ];
    }
    if (!is_array($form_state->get('intent')) && ($draft = $form_state->get('draft'))) {
      $this->draftFields($form, $form_state, [
        'owner' => $draft['owner']['uid'] ?? NULL,
        'contact' => $draft['owner']['contact']['value'] ?? $draft['contact']['value'] ?? '',
      ] + $draft, FALSE);
    }
    $submit = match ($command) {
      'issue' => 'action.issueInvitation', 'resend' => 'invitation.resendConfirm',
      'revokeInvitation' => 'invitation.revokeConfirm', 'close' => 'close.confirm',
      default => $prefix . '.submit',
    };
    $form['actions']['#type'] = 'actions';
    $first = !is_array($form_state->get('intent'));
    $needs_edit = ($result['outcome'] ?? '') === 'conflict' || in_array($result['reason'] ?? '', [
      'invalid',
      'contact_refused',
      'identity_not_found',
    ], TRUE);
    if (!$form_state->get('acknowledged') && ($first || !$needs_edit || !empty($result['unconfirmed']))) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#name' => 'retry',
        '#value' => $this->copy->text($first ? 'review.button' : ($form_state->get('await_confirmation') ? $submit : 'recovery.retryExact')),
      ];
    }
    if ($first) {
      $form['initial_review_help'] = $this->message($this->copy->text('review.buttonHelp'));
    }
    $can_apply = FALSE;
    if (!$first && !$form_state->get('await_confirmation')) {
      $form['actions']['review'] = [
        '#type' => 'submit',
        '#name' => 'review',
        '#value' => $this->copy->text('recovery.reviewButton'),
        '#submit' => ['::submitForm'],
        '#limit_validation_errors' => [],
      ];
      $form['review_help'] = $this->message($this->copy->text('recovery.reviewHelp'));
      $can_apply = empty($result['unconfirmed']) && !$form_state->get('await_confirmation') && $result && $form_state->get('recovery_reviewed') && $review && isset(DiscoveryConsoleController::actions($review)[$command]);
      if ($can_apply) {
        $form['actions']['apply'] = [
          '#type' => 'submit',
          '#name' => 'apply',
          '#value' => $this->copy->text('recovery.applyButton'),
        ];
        $form['apply_help'] = $this->message($this->copy->text('recovery.applyHelp'));
      }
      if ($form_state->get('recovery_reviewed') && $review && !$can_apply && empty($result['unconfirmed'])) {
        $form['unavailable'] = $this->message($this->copy->text('recovery.unavailable', [
          'name' => $review['display_name'],
          'state' => $this->copy->text(DiscoveryConsoleController::stateKey($review), $values),
        ]));
      }
      elseif ($form_state->get('review_unavailable')) {
        $form['unavailable'] = $this->message($this->copy->error($form_state->get('review_unavailable')));
      }
      if (empty($result['unconfirmed'])) {
        $form['actions']['discard'] = [
          '#type' => 'submit',
          '#name' => 'discard',
          '#value' => $this->copy->text('recovery.discardButton'),
          '#submit' => ['::submitForm'],
          '#limit_validation_errors' => [],
        ];
      }
    }
    if (!$first) {
      $frozen = $form_state->get('intent')['input'];
      $this->draftFields($form, $form_state, [
        'owner' => $frozen['owner']['uid'] ?? NULL,
        'contact' => $frozen['owner']['contact']['value'] ?? $frozen['contact']['value'] ?? '',
        'confirm' => TRUE,
      ] + $frozen, !$can_apply);
    }
    if (!$form_state->get('acknowledged') && $form_state->get('review_nonce')) {
      if (isset($result['delivery_id']) && $this->currentUser()->hasPermission(InvitationDelivery::PERMISSION)) {
        $form['delivery_review'] = [
          '#type' => 'link',
          '#title' => $this->t('Review invitation delivery'),
          '#url' => Url::fromRoute('agency_host.console_delivery', ['delivery' => $result['delivery_id']], [
            'query' => ['return' => $command, 'return_review' => $form_state->get('review_nonce')],
          ]),
          '#attributes' => ['id' => 'delivery-' . $result['delivery_id']],
        ];
      }
      $this->acknowledgement($form, $result);
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
    $button = $form_state->getTriggeringElement()['#name'] ?? 'retry';
    if ($button === 'edit') {
      $draft = $this->reviews->edit($this->getRequest(), $form_state->get('review_nonce'), $form_state->get('relationship'), $form_state->get('command'));
      $form_state->set('draft', $draft)->set('intent', NULL)->set('review_nonce', NULL)->set('review', NULL)->set('await_confirmation', FALSE)->set('result', NULL)->setRebuild();
      $this->getRequest()->request->remove('review_nonce');
      $form_state->setUserInput([]);
      return;
    }

    if ($button === 'acknowledge') {
      $this->requireExplicitAction($form_state, 'acknowledge');
      $this->reviews->acknowledge($this->getRequest(), $form_state->get('review_nonce'), $form_state->get('relationship'), $form_state->get('command'));
      $form_state->set('acknowledged', TRUE);
      if (($form_state->get('result')['outcome'] ?? '') === 'committed') {
        $form_state->setRedirect('agency_host.console_detail', [
          'relationship' => $form_state->get('relationship'),
        ]);
      }
      else {
        $form_state->setRebuild();
      }
      return;
    }

    if ($button === 'discard') {
      if (!$form_state->get('acknowledged')) {
        $record = $this->reviews->read($this->getRequest(), $form_state->get('review_nonce'), $form_state->get('relationship'), $form_state->get('command'));
        if ($record['attempted']) {
          if (!$form_state->get('acknowledged')) {
            $this->reviews->acknowledge($this->getRequest(), $form_state->get('review_nonce'), $form_state->get('relationship'), $form_state->get('command'));
          }
        }
        else {
          $this->reviews->edit($this->getRequest(), $form_state->get('review_nonce'), $form_state->get('relationship'), $form_state->get('command'));
        }
      }
      $form_state->set('intent', NULL)->set('draft', NULL)->setUserInput([]);

      $form_state->setRedirect('agency_host.console_detail', [
        'relationship' => $form_state->get('relationship'),
      ]);
      return;
    }
    if ($button === 'review') {
      $queue = $this->application->queue($this->getRequest());
      $form_state->set('review', NULL)->set('recovery_reviewed', FALSE)->set('review_unavailable', ($queue['outcome'] ?? '') === 'available' ? ['reason' => 'not_found'] : $queue);
      foreach ($queue['contexts'] ?? [] as $row) {
        if ($row['id'] === $form_state->get('relationship')) {
          $form_state->set('review', $row)->set('recovery_reviewed', TRUE)->set('review_unavailable', NULL);
        }
      }
      $form_state->setRebuild();
      return;
    }
    if ($button === 'apply') {
      $review = $form_state->get('review');
      if (!$review || !$form_state->get('recovery_reviewed') || !empty($form_state->get('result')['unconfirmed'])
        || !isset(DiscoveryConsoleController::actions($review)[$form_state->get('command')])) {
        $form_state->set('result', ['outcome' => 'refused', 'reason' => 'not_found'])->setRebuild();
        return;
      }
      if (!$form_state->get('acknowledged')) {
        $this->reviews->acknowledge($this->getRequest(), $form_state->get('review_nonce'), $form_state->get('relationship'), $form_state->get('command'));
      }
      $this->getRequest()->request->remove('review_nonce');
      $form_state->set('review_nonce', NULL)->set('acknowledged', FALSE)->set('result', NULL);
      $form_state->set('intent', NULL)->set('version', $review['version'])->set('review', NULL)->set('await_confirmation', FALSE);
    }
    $command = $form_state->get('command');
    $intent = $form_state->get('intent');
    if (!$intent) {
      try {
        $input = match ($command) {
          'setNextAction' => $this->actionInput($form_state, (string) ($form_state->getValue('side') ?? 'agency')),
          'pauseRelationship' => $this->actionInput($form_state, 'agency'),
          'close' => [
            'category' => $form_state->getValue('category'),
            'message' => $form_state->getValue('message'),
            'contact' => $this->contact((string) $form_state->getValue('contact')),
          ],
          'reassign' => [
            'owner' => [
              'uid' => (int) $form_state->getValue('owner'),
              'contact' => $this->contact((string) $form_state->getValue('contact')),
            ],
          ],
          'moveForward' => [
            'target_purpose' => $form_state->getValue('target_purpose'),
            'message' => $form_state->getValue('message'),
          ],
          default => [],
        };
        $intent = [
          'input' => $input,
          'key' => bin2hex(random_bytes(24)),
          'version' => $form_state->get('version'),
        ];
        $form_state->set('draft', $input)->set('review', NULL);
        $queue = $this->application->queue($this->getRequest());
        foreach ($queue['contexts'] ?? [] as $row) {
          if ($row['id'] === $form_state->get('relationship')) {
            $form_state->set('review', $row);
          }
        }
        $review = $form_state->get('review');
        if (!$review) {
          throw new Refusal('not_found');
        }
        $intent['version'] = $review['version'];
        $this->captureReview($form_state, $form_state->get('relationship'), $command, $intent, $review);
        $form_state->setRebuild();
        return;
      }
      catch (Refusal $error) {
        $form_state->set('intent', NULL)->set('review_nonce', NULL)->set('await_confirmation', FALSE);
        $form_state->set('result', ['outcome' => 'refused', 'reason' => $error->reason])->setRebuild();
        return;
      }
    }
    $record = $this->reviews->read($this->getRequest(), $intent['review_nonce'], $form_state->get('relationship'), $command);
    $intent = $record['intent'] + ['review_nonce' => $intent['review_nonce'], 'command' => $command];
    $this->reviews->attempted($this->getRequest(), $intent['review_nonce']);
    $form_state->set('await_confirmation', FALSE);
    $result = $this->application->intent($this->getRequest(), $form_state->get('relationship'), $intent, fn(): array => \Drupal::service('agency_host.invitation_delivery')->command($this->getRequest(), $form_state->get('relationship'), $command, $intent));
    // Bearer handles never enter cached form state or general flash messages.
    if (isset($result['invitation']['token'])) {
      $form_state->setTemporaryValue('invitation', [
        'url' => $this->application->invitationOrigin() . '/invite/' . $result['invitation']['token'],
        'expires' => $result['invitation']['expires'],
      ]);
    }
    $result = $this->reviews->result($this->getRequest(), $intent['review_nonce'], $result);
    unset($result['invitation']);
    $form_state->set('result', $result)->setRebuild();
  }

}
