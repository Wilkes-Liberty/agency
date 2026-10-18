<?php

declare(strict_types=1);

namespace Drupal\agency_host\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Console\ConsoleFieldInventory;
use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Policy;
use Drupal\agency_staff\RelationshipCommands;
use Drupal\agency_host\Console\ConsoleApplication;
use Drupal\agency_host\Console\ConsoleCopy;
use Drupal\agency_host\Console\ConsoleReviewStore;
use Drupal\agency_host\Controller\DiscoveryConsoleController;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared presentation helpers; no business state or authority lives in forms.
 */
abstract class ConsoleFormBase extends FormBase {

  public function __construct(
    protected ConsoleApplication $application,
    protected ConsoleCopy $copy,
    protected Policy $policy,
    protected ActorAccess $actors,
    protected EntityTypeManagerInterface $entities,
    protected ConsoleReviewStore $reviews,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('agency_host.console'), $container->get('agency_host.console_copy'), $container->get('agency_staff.policy'), $container->get('agency_staff.access'), $container->get('entity_type.manager'), $container->get('agency_host.console_reviews'));
  }

  /**
   * Attaches private rendering and preserves server-side form state.
   */
  protected function shell(array $form, FormStateInterface $state): array {
    if (!$state->has('console_created')) {
      $state->set('console_created', \Drupal::time()->getCurrentTime());
    }
    $form['#cache']['max-age'] = 0;
    $form['#after_build'][] = [ConsoleFieldInventory::class, 'check'];
    $form['#attributes']['class'][] = 'agency-console';
    $form['#attached']['library'][] = 'agency_host/console';
    $form['#attributes']['autocomplete'] = 'off';
    if (!$this->getRequest()->isMethodSafe()) {
      $state->setCached();
    }
    $form['queue'] = [
      '#type' => 'link',
      '#title' => $this->copy->text('title'),
      '#url' => Url::fromRoute('agency_host.console'),
    ];
    $form['held'] = [
      '#type' => 'link',
      '#title' => $this->copy->text('recovery.capacityReturn'),
      '#url' => Url::fromRoute('agency_host.console_held'),
    ];
    return $form;
  }

  /**
   * Restores a route-bound review after the submitted Form API cache expires.
   */
  protected function restoreReview(array &$form, FormStateInterface $state, string $context, string $command): void {
    $request = $this->getRequest();
    if ($state->get('acknowledged')) {
      return;
    }
    $nonce = $request->request->get('review_nonce') ?? $state->get('review_nonce')
      ?? ($request->isMethodSafe() ? $request->query->get('review') : NULL);
    if (!$nonce && $request->isMethodSafe()) {
      $pending = $this->application->pending($request, $context);
      $nonce = ($pending['command'] ?? '') === $command ? ($pending['nonce'] ?? NULL) : NULL;
    }
    if ($nonce !== NULL) {
      if (!is_string($nonce)) {
        throw new Refusal('not_found');
      }
      $record = $this->reviews->read($request, $nonce, $context, $command);
      $intent = $record['intent'] + ['review_nonce' => $nonce, 'command' => $command];
      $state->set('review_nonce', $nonce)->set('intent', $intent);
      $state->set('confirmation_projection', $record['projection'])->set('version', $intent['version'] ?? NULL);
      $state->set('display_name', $record['projection']['display_name'] ?? $intent['input']['display_name'] ?? '');
      $state->set('agency_name', $record['projection']['agency_name'] ?? '');
      $state->set('sides', $record['projection']['set_next_action_sides'] ?? ['agency']);
      $state->set('await_confirmation', !$record['attempted']);
      if ($record['result']) {
        $state->set('result', $record['result']);
      }
      $form['review_nonce'] = [
        '#type' => 'hidden',
        '#value' => $nonce,
        '#agency_field_purpose' => 'review.nonce',
      ];
    }
  }

  /**
   * Stores an immutable review and exposes only its untrusted lookup nonce.
   */
  protected function captureReview(FormStateInterface $state, string $context, string $command, array $intent, array $projection): void {
    $nonce = $this->reviews->capture($this->getRequest(), $context, $command, $intent, $projection);
    // An identical pending capture may already own this nonce and its key.
    $record = $this->reviews->read($this->getRequest(), $nonce, $context, $command);
    $intent = $record['intent'];
    $state->set('review_nonce', $nonce)->set('intent', $intent + [
      'review_nonce' => $nonce,
      'command' => $command,
    ]);
    $state->set('confirmation_projection', $record['projection'])->set('review', NULL)->set('recovery_reviewed', FALSE)->set('await_confirmation', TRUE);
  }

  /**
   * Keeps one submitted draft visible without accepting silently ignored edits.
   */
  protected function draftFields(array &$form, FormStateInterface $state, array $values, bool $disabled): void {
    $raw_input = $state->getUserInput() ?? [];
    foreach ($form as $name => &$element) {
      if (!is_array($element) || !isset($element['#agency_field_purpose']) || ($element['#type'] ?? '') === 'hidden') {
        continue;
      }
      $value = $values[$name] ?? NULL;
      if ($name === 'expected_by' && is_int($value)) {
        $value = DrupalDateTime::createFromTimestamp($value);
      }
      $element['#default_value'] = $value;
      $element['#disabled'] = $disabled;
      if ($disabled) {
        unset($raw_input[$name]);
      }
    }
    unset($element);
    $state->setUserInput($raw_input);
  }

  /**
   * Displays acknowledgement only for a definitive retained result.
   */
  protected function acknowledgement(array &$form, ?array $result): void {
    if ($result && empty($result['unconfirmed']) && ($result['outcome'] ?? '') !== 'uncertain') {
      $form['actions']['#type'] = 'actions';
      $form['actions']['acknowledge'] = [
        '#type' => 'submit',
        '#name' => 'acknowledge',
        '#value' => $this->copy->text('recovery.ackButton'),
        '#submit' => ['::submitForm'],
        '#limit_validation_errors' => [],
      ];
      $form['ack_help'] = $this->message($this->copy->text('recovery.ackHelp'));
      if (($result['outcome'] ?? '') !== 'committed') {
        $form['ack_not_saved'] = $this->message($this->copy->text('recovery.ackNotSavedNote'));
      }
    }
  }

  /**
   * Expiry is never interpreted as a command result or permission to replay.
   */
  protected function expiredForm(array $form, FormStateInterface $state, string $context, string $command, string $current_name = ''): array {
    $request = $this->getRequest();
    $nonce = $request->request->get('review_nonce') ?? $state->get('review_nonce')
      ?? ($request->isMethodSafe() ? $request->query->get('review') : NULL)
      ?? $this->application->pending($request, $context)['nonce'] ?? NULL;
    $form['expired_heading'] = $this->message($this->copy->text('recovery.expiredHeading'));
    $form['expired_help'] = $this->message($this->copy->text('recovery.expiredEntries') . ' ' . $this->copy->text('recovery.expiredCheck'));
    if (is_string($nonce)) {
      try {
        $record = $this->reviews->read($request, $nonce, $context, $command, TRUE);
        $form['expired_body'] = $this->message($current_name !== ''
          ? $this->copy->text('recovery.expiredBody', ['name' => $current_name])
          : $this->copy->text('recovery.expiredBodyGeneric'));
        if ($record['expires'] <= \Drupal::time()->getCurrentTime()) {
          $state->set('review_nonce', $nonce)->set('expired_context', $context)->set('expired_command', $command);
          $form['review_nonce'] = [
            '#type' => 'hidden',
            '#value' => $nonce,
            '#agency_field_purpose' => 'review.nonce',
          ];
          $form['actions']['#type'] = 'actions';
          $form['actions']['expired_continue'] = [
            '#type' => 'submit',
            '#name' => 'expired_continue',
            '#value' => $this->copy->text('recovery.expiredContinue'),
          ];
          $form['expired_continue_help'] = $this->message($this->copy->text('recovery.expiredContinueHelp'));
        }
      }
      catch (Refusal) {
        // Missing or foreign records expose no draft and no release control.
      }
    }
    return $form;
  }

  /**
   * Describes bounded capacity without promising that waiting removes holds.
   */
  protected function capacityForm(array $form): array {
    $form['capacity_heading'] = DiscoveryConsoleController::paragraph($this->copy->text('recovery.capacityHeading'), 'h2');
    $form['capacity_body'] = $this->message($this->copy->text('recovery.capacityBody'));
    $form['capacity_intro'] = $this->message($this->copy->text('recovery.capacityStepsIntro'));
    $steps = [];
    foreach ($this->reviews->listing($this->getRequest()) as $record) {
      $key = !$record['attempted'] ? 'capacityDraft'
        : ($record['expires'] <= \Drupal::time()->getCurrentTime() ? 'capacityExpired'
          : ($record['result'] && empty($record['result']['unconfirmed']) && ($record['result']['outcome'] ?? '') !== 'uncertain' ? 'capacityAck' : 'capacityUnknown'));
      $steps[$key] = $this->message($this->copy->text('recovery.' . $key));
    }
    $form['capacity_steps'] = ['#theme' => 'item_list', '#items' => array_values($steps)];
    $form['guard_scope'] = $this->message($this->copy->text('recovery.guardScope'));
    return $form;
  }

  /**
   * Handles the protected, explicit local expiry release with no Agency write.
   */
  protected function releaseExpired(FormStateInterface $state): bool {
    if (($state->getTriggeringElement()['#name'] ?? '') !== 'expired_continue') {
      return FALSE;
    }
    $this->requireExplicitAction($state, 'expired_continue');
    $this->reviews->releaseExpired($this->getRequest(), $state->get('review_nonce'), $state->get('expired_context'), $state->get('expired_command'));
    $state->setRedirect('agency_host.console');
    return TRUE;
  }

  /**
   * Refuses Form API's implicit first-button fallback for explicit releases.
   */
  protected function requireExplicitAction(FormStateInterface $state, string $name): void {
    $button = $state->getTriggeringElement();
    $request = $this->getRequest();
    $value = $request->request->all()[$name] ?? NULL;
    if (!$request->isMethod('POST') || ($button['#name'] ?? '') !== $name
      || !is_string($value) || $value !== (string) ($button['#value'] ?? '')) {
      throw new Refusal('not_found');
    }
  }

  /**
   * Distinguishes definitive receipts from an outcome still awaiting evidence.
   */
  protected function resultHeading(array &$form, array $result, array $values): void {
    $unknown = !empty($result['unconfirmed']) || ($result['outcome'] ?? '') === 'uncertain';
    $key = $unknown ? 'unconfirmedHeading'
      : (($result['outcome'] ?? '') === 'committed' ? 'confirmedSaved' : 'confirmedNotSaved');
    $form['result_heading'] = DiscoveryConsoleController::paragraph($this->copy->text('recovery.' . $key), 'h2');
    if ($unknown) {
      $form['guard_scope'] = $this->message($this->copy->text('recovery.guardScope'));
      $form['unconfirmed_flag'] = $this->message($this->copy->text('flag.unconfirmed'));
    }
    elseif (($result['outcome'] ?? '') === 'conflict') {
      $form['changed_heading'] = DiscoveryConsoleController::paragraph($this->copy->text('recovery.changedHeading'), 'h2');
      $form['changed_body'] = $this->message($this->copy->text('recovery.changedBody', $values));
    }
  }

  /**
   * Blocks another command while retaining a route back to the original result.
   */
  protected function pendingForm(array $form, string $context, string $command): ?array {
    $pending = $this->application->pending($this->getRequest(), $context);
    if (!$pending || ($pending['command'] ?? '') === $command) {
      return NULL;
    }
    $form['blocked'] = $this->message($this->copy->text('recovery.blocked'));
    $form['guard_scope'] = $this->message($this->copy->text('recovery.guardScope'));
    $form['return_request'] = [
      '#type' => 'link',
      '#title' => $this->copy->text(empty($pending['unconfirmed']) ? 'recovery.returnResult' : 'recovery.returnPending'),
      '#url' => Url::fromRoute('agency_host.console_command', [
        'relationship' => $context,
        'command' => $pending['command'],
      ]),
    ];
    return $form;
  }

  /**
   * Adds a reviewed, escaped message without storing sensitive flash content.
   */
  protected function message(string $text): array {
    return [
      '#type' => 'container',
      '#attributes' => ['role' => 'status', 'tabindex' => '-1', 'class' => ['agency-console__status']],
      'text' => DiscoveryConsoleController::paragraph($text),
    ];
  }

  /**
   * Builds one labelled input from the field-purpose inventory.
   */
  protected function field(string $purpose, string $title, string $type = 'textfield', int $maximum = 1000): array {
    $inventory = json_decode(file_get_contents(__DIR__ . '/../../resources/console-fields.json'), TRUE, 8, JSON_THROW_ON_ERROR);
    if (!isset($inventory['fields'][$purpose])) {
      throw new \LogicException('Undeclared console field.');
    }
    $element = [
      '#type' => $type,
      '#title' => $title,
      '#required' => TRUE,
      '#agency_field_purpose' => $purpose,
    ];
    if (in_array($type, ['textfield', 'textarea', 'email', 'url'], TRUE)) {
      $element['#maxlength'] = $maximum;
    }
    return $element;
  }

  /**
   * Contact kind follows the explicit syntax, never an unsafe link guess.
   */
  protected function contact(string $value): array {
    $value = trim($value);
    $kind = filter_var($value, FILTER_VALIDATE_EMAIL) ? 'email'
      : (str_starts_with($value, 'https://') ? 'url' : 'phone');
    if (($kind === 'phone' && !preg_match('/^\+?[0-9 ()-]{3,32}$/D', $value))
      || ($kind === 'url' && (!filter_var($value, FILTER_VALIDATE_URL) || isset(parse_url($value)['user']) || isset(parse_url($value)['pass'])))) {
      throw new Refusal('invalid');
    }
    return RelationshipCommands::contact(['kind' => $kind, 'value' => $value]);
  }

  /**
   * Converts the date widget's timezone-aware value to a domain timestamp.
   */
  protected function actionInput(FormStateInterface $state, string $side): array {
    $date = $state->getValue('expected_by');
    return [
      'summary' => (string) $state->getValue('summary'),
      'why_it_helps' => (string) $state->getValue('why_it_helps'),
      'expected_by' => $date instanceof DrupalDateTime ? $date->getTimestamp() : 0,
      'optional' => TRUE,
      'side' => $side,
    ];
  }

  /**
   * Adds the public dated-obligation fields with their reviewed copy.
   */
  protected function actionFields(array &$form, string $prefix, array $values, array $sides): void {
    $summary = $prefix === 'prepare' ? 'nextSummaryLabel' : 'summaryLabel';
    $why = $prefix === 'prepare' ? 'nextWhyLabel' : 'whyLabel';
    $by = $prefix === 'prepare' ? 'nextByLabel' : 'byLabel';
    $form['summary'] = $this->field('next_action.summary', $this->copy->text($prefix . '.' . $summary, $values), 'textarea');
    $form['why_it_helps'] = $this->field('next_action.why', $this->copy->text($prefix . '.' . $why, $values), 'textarea');
    $form['expected_by'] = $this->field('next_action.expected_by', $this->copy->text($prefix . '.' . $by, $values), 'datetime');
    $timezone = date_default_timezone_get();
    $form['expected_by']['#date_timezone'] = $timezone;
    $form['expected_by']['#description'] = $this->copy->text('timezoneHelp', ['timezone' => $timezone]);
    if (count($sides) > 1) {
      $form['side'] = $this->field('next_action.side', $this->copy->text($prefix === 'prepare' ? 'prepare.nextSideLabel' : 'nextStep.sideLabel'), 'select');
      $form['side']['#options'] = [
        'agency' => $this->copy->text('prepare.nextSideAgency', $values),
        'participant' => $this->copy->text('prepare.nextSideParticipant'),
      ];
    }
  }

}
