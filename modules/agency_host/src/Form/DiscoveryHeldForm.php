<?php

declare(strict_types=1);

namespace Drupal\agency_host\Form;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Exposes session-owned recovery metadata without stale relationship details.
 */
final class DiscoveryHeldForm extends ConsoleFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agency_host_console_held';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->shell($form, $form_state);
    $queue = $this->application->queue($this->getRequest());
    if (($queue['outcome'] ?? '') !== 'available') {
      $form['error'] = $this->message($this->copy->error($queue));
      return $form;
    }
    $form['heading'] = $this->message($this->copy->text('recovery.heldHeading'));
    $form['guard_scope'] = $this->message($this->copy->text('recovery.guardScope'));
    if ($error = $form_state->get('result')) {
      $form['error'] = $this->message($this->copy->error($error));
      $form['error']['#attributes']['data-agency-focus'] = 'true';
    }
    try {
      $records = $this->reviews->listing($this->getRequest());
    }
    catch (Refusal $error) {
      $form['error'] = $this->message($this->copy->error(['reason' => $error->reason]));
      return $form;
    }
    if (!$records) {
      $form['empty'] = $this->message($this->copy->text('recovery.heldEmpty'));
      return $form;
    }
    $request = $this->getRequest();
    $nonce = $request->request->get('review_nonce') ?? $request->query->get('review');
    $rows = array_column($queue['contexts'], NULL, 'id');
    foreach ($records as $key => $record) {
      $label = $this->label($record);
      // Only current public projections can supply a person's name.
      $name = $rows[$record['context']]['display_name'] ?? NULL;
      $form['requests'][$key] = [
        '#type' => 'link',
        '#title' => ($name ? $name . ': ' : '') . $label,
        '#url' => Url::fromRoute('agency_host.console_held', [], ['query' => ['review' => $key]]),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
    }
    if (!is_string($nonce) || !isset($records[$nonce])) {
      return $form;
    }
    $record = $records[$nonce];
    $form['review_nonce'] = ['#type' => 'hidden', '#value' => $nonce, '#agency_field_purpose' => 'review.nonce'];
    $form['status'] = $this->message($this->label($record));
    $expired = $record['expires'] <= \Drupal::time()->getCurrentTime();
    if (!$record['attempted']) {
      $form['actions']['discard'] = [
        '#type' => 'submit',
        '#name' => 'discard',
        '#value' => $this->copy->text('recovery.discardButton'),
      ];
    }
    elseif ($expired) {
      $form['expired_body'] = $this->message(isset($rows[$record['context']])
        ? $this->copy->text('recovery.expiredBody', ['name' => $rows[$record['context']]['display_name']])
        : $this->copy->text('recovery.expiredBodyGeneric'));
      $form['entries'] = $this->message($this->copy->text('recovery.expiredEntries'));
      $form['check'] = $this->message($this->copy->text('recovery.expiredCheck'));
      $form['actions']['expired_continue'] = [
        '#type' => 'submit',
        '#name' => 'expired_continue',
        '#value' => $this->copy->text('recovery.expiredContinue'),
      ];
      $form['continue_help'] = $this->message($this->copy->text('recovery.expiredContinueHelp'));
    }
    else {
      $this->acknowledgement($form, $record['result']);
    }
    if (!$expired && ($record['context'] === 'prepare' || isset($rows[$record['context']]))) {
      $route = $record['context'] === 'prepare' ? 'agency_host.console_prepare' : 'agency_host.console_command';
      $parameters = $record['context'] === 'prepare' ? [] : [
        'relationship' => $record['context'],
        'command' => $record['command'],
      ];
      $form['open'] = [
        '#type' => 'link',
        '#title' => $this->copy->text(empty($record['result']['unconfirmed']) && $record['result'] ? 'recovery.returnResult' : 'recovery.returnPending'),
        '#url' => Url::fromRoute($route, $parameters, ['query' => ['review' => $nonce]]),
      ];
    }
    elseif (!$expired && $record['attempted'] && (!$record['result'] || !empty($record['result']['unconfirmed']))) {
      $form['access_lost'] = $this->message($this->copy->text('recovery.accessLost'));
    }
    return $form;
  }

  /**
   * Classifies minimal evidence without loading expired or unauthorized drafts.
   */
  private function label(array $record): string {
    if ($record['expires'] <= \Drupal::time()->getCurrentTime()) {
      return $this->copy->text('recovery.expiredHeading');
    }
    if (!$record['attempted']) {
      return $this->copy->text('review.heading');
    }
    $result = $record['result'];
    return $this->copy->text('recovery.' . (!$result || !empty($result['unconfirmed']) || ($result['outcome'] ?? '') === 'uncertain'
      ? 'unconfirmedHeading' : (($result['outcome'] ?? '') === 'committed' ? 'confirmedSaved' : 'confirmedNotSaved')));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->application->run($this->getRequest(), function () use ($form_state): array {
      $button = $form_state->getTriggeringElement()['#name'] ?? '';
      $this->requireExplicitAction($form_state, $button);
      $nonce = $this->getRequest()->request->get('review_nonce');
      $records = $this->reviews->listing($this->getRequest());
      if (!is_string($nonce) || !isset($records[$nonce])) {
        throw new Refusal('not_found');
      }
      $record = $records[$nonce];
      match ($button) {
        'discard' => $this->reviews->discard($this->getRequest(), $nonce),
        'expired_continue' => $this->reviews->releaseExpired($this->getRequest(), $nonce, $record['context'], $record['command']),
        'acknowledge' => $this->reviews->acknowledge($this->getRequest(), $nonce, $record['context'], $record['command']),
        default => throw new Refusal('not_found'),
      };
      return ['outcome' => 'available'];
    }, TRUE);
    if (($result['outcome'] ?? '') === 'available') {
      $form_state->setRedirect('agency_host.console_held');
    }
    else {
      $form_state->set('result', $result)->setRebuild();
    }
  }

}
