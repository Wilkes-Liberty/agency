<?php

declare(strict_types=1);

namespace Drupal\agency_host\Controller;

use Drupal\agency_host\Form\InvitationDeliveryForm;
use Drupal\agency_staff\Policy;
use Drupal\agency_host\Console\ConsoleApplication;
use Drupal\agency_host\Console\ConsoleCopy;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the authorized public projection, without querying domain storage.
 */
final class DiscoveryConsoleController extends ControllerBase {

  public function __construct(
    private readonly ConsoleApplication $application,
    private readonly ConsoleCopy $copy,
    private readonly Policy $policy,
    private readonly DateFormatterInterface $dates,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('agency_host.console'), $container->get('agency_host.console_copy'), $container->get('agency_staff.policy'), $container->get('date.formatter'), $container->get('datetime.time'));
  }

  /**
   * Lists public rows in the domain's responsibility order, without notes.
   */
  public function queue(Request $request): array {
    $result = $this->application->queue($request);
    $build = $this->base();
    if (($result['outcome'] ?? '') !== 'available') {
      $build['error'] = self::paragraph($this->copy->error($result));
      return $build;
    }
    $build['intro'] = self::paragraph($this->copy->text('intro'));
    $build['sort'] = self::paragraph($this->copy->text('queue.sortNote'));
    $build['prepare'] = [
      '#type' => 'link',
      '#title' => $this->copy->text('action.prepare'),
      '#url' => Url::fromRoute('agency_host.console_prepare'),
    ];
    $build['alerts'] = $this->alerts($result['alerts'] ?? []);
    if ($result['contexts'] === []) {
      $build['empty'] = self::paragraph($this->copy->text('empty'));
    }
    foreach ($result['contexts'] as $index => $row) {
      $build['rows'][$index] = $this->row($row, FALSE);
    }
    return $build;
  }

  /**
   * Shows owner-only notes only when present in the freshly authorized row.
   */
  public function detail(string $relationship, Request $request): array {
    $result = $this->application->queue($request);
    $build = $this->base();
    $build['queue'] = [
      '#type' => 'link',
      '#title' => $this->copy->text('title'),
      '#url' => Url::fromRoute('agency_host.console'),
    ];
    foreach ($result['contexts'] ?? [] as $row) {
      if ($row['id'] === $relationship) {
        $build['detail'] = $this->row($row, TRUE);
        $deliveries = \Drupal::service('agency_host.invitation_delivery')->listing($request, $relationship);
        $build['deliveries'] = ['#theme' => 'item_list', '#items' => []];
        foreach ($deliveries['deliveries'] ?? [] as $index => $delivery) {
          $build['deliveries']['#items'][] = [
            '#type' => 'link',
            '#title' => $this->t('Delivery record @number, @date — @state', [
              '@number' => $index + 1,
              '@date' => gmdate('Y-m-d H:i:s', (int) $delivery['created']) . ' UTC',
              '@state' => InvitationDeliveryForm::stateLabel($delivery['state']),
            ]),
            '#url' => Url::fromRoute('agency_host.console_delivery', ['delivery' => $delivery['id']]),
            '#attributes' => ['id' => 'delivery-' . $delivery['id']],
          ];
        }
        return $build;
      }
    }
    $build['error'] = self::paragraph($this->copy->error($result['outcome'] === 'available' ? [
      'reason' => 'not_found',
    ] : $result));
    return $build;
  }

  /**
   * Builds a cache-disabled responsive container.
   */
  private function base(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['agency-console']],
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['agency_host/console']],
      '#title' => $this->copy->text('title'),
    ];
  }

  /**
   * Renders a bounded text block with no markup interpretation.
   */
  public static function paragraph(string $text, string $tag = 'p'): array {
    return ['#type' => 'html_tag', '#tag' => $tag, 'text' => ['#plain_text' => $text]];
  }

  /**
   * Formats only public dates; the configured site timezone is explicit.
   */
  private function date(?int $timestamp): string {
    return $timestamp ? $this->dates->format($timestamp, 'custom', 'Y-m-d H:i T') : $this->copy->text('queue.noDate');
  }

  /**
   * Shows observed elapsed time with its exact date, without guessing a future.
   */
  private function lastAction(?int $timestamp): string {
    if ($timestamp === NULL) {
      return $this->copy->text('queue.noAction');
    }
    $date = $this->dates->format($timestamp, 'custom', 'Y-m-d H:i T');
    $now = $this->time->getCurrentTime();
    return $timestamp > $now ? $date : $this->copy->text('queue.lastActionAgo', [
      'time' => (string) $this->dates->formatDiff($timestamp, $now, ['granularity' => 2, 'langcode' => 'en']),
      'date' => $date,
    ]);
  }

  /**
   * Renders the observed alert timestamp, never an inferred cleared status.
   */
  private function alerts(array $alerts): array {
    $build = [];
    foreach ($alerts as $alert) {
      $key = match ($alert['reason'] ?? '') {
        'recording_unavailable' => 'recording',
        'identity_unavailable' => 'identity',
        default => NULL,
      };
      if ($key !== NULL) {
        $build[] = self::paragraph($this->copy->text('alert.' . $key, [
          'date' => $this->date($alert['last_observed_at'] ?? NULL),
        ]));
      }
    }
    if ($build !== []) {
      $build[] = self::paragraph($this->copy->text('alert.lastSeen'));
    }
    return $build;
  }

  /**
   * Restricts controls to supported actions in the current public projection.
   */
  public static function actions(array $row): array {
    $labels = [
      'issue' => 'previewInvitation',
      'resend' => 'resendInvitation',
      'revokeInvitation' => 'revokeInvitation',
      'pauseRelationship' => 'pause',
      'resumeRelationship' => 'resume',
      'close' => 'close',
      'moveForward' => 'proposeMoveForward',
    ];
    $result = array_intersect_key($labels, array_flip($row['permitted_actions'] ?? []));
    if (in_array('setNextAction', $row['permitted_actions'] ?? [], TRUE)) {
      $result['reassign'] = 'reassign';
      if (in_array('next_action', $row['set_next_action_modes'] ?? [], TRUE)) {
        $result['setNextAction'] = 'setNextStep';
      }
    }
    return $result;
  }

  /**
   * Selects a reviewed state label from the current public projection.
   */
  public static function stateKey(array $row): string {
    return 'states.' . match ($row['state']) {
      'paused' => ($row['pause_origin'] ?? '') === 'agency' ? 'pausedByAgency' : 'pausedByPerson',
      'moved_forward' => 'movedForward',
      'invited' => in_array('issue', $row['permitted_actions'] ?? [], TRUE) ? 'invitedNoInvitation' : 'invited',
      default => $row['state'],
    };
  }

  /**
   * Uses escaped public fields; notes are absent from the list representation.
   */
  private function row(array $row, bool $detail): array {
    $values = [
      'name' => $row['display_name'],
      'agencyName' => $row['agency_name'],
      'ownerName' => $row['owner']['name'] ?? '',
    ];
    $values['state'] = $this->copy->text(self::stateKey($row), $values);
    $build = ['#type' => 'container', '#attributes' => ['class' => ['agency-console__row']]];
    $build['name'] = [
      '#type' => 'link',
      '#title' => $this->copy->text('queue.rowLabel', $values),
      '#url' => Url::fromRoute('agency_host.console_detail', [
        'relationship' => $row['id'],
      ]),
    ];
    $waiting = match ($row['display_waiting_on'] ?? $row['waiting_on']) {
      'agency' => 'waitingAgency', 'participant' => 'waitingParticipant', default => 'waitingNobody',
    };
    $fields = [
      'colWaitingOn' => $this->copy->text('queue.' . $waiting, $values),
      'colNextStep' => ($row['next_action']['code'] ?? '') === 'agency_response' ? $this->copy->text('queue.nextStepAgencyResponse', $values) : ($row['next_action']['summary'] ?? $this->copy->text('queue.noNextStep')),
      'colExpectedBy' => $this->date($row['next_action']['expected_by'] ?? NULL),
      'colOwner' => ($row['owner']['name'] ?? '') . (isset($row['owner_changed_at']) ? ' — ' . $this->date($row['owner_changed_at']) : ''),
      'colLastAction' => $this->lastAction($row['last_agency_action_at'] ?? NULL),
    ];
    if (($row['next_action']['code'] ?? '') === 'agency_response') {
      $build['agency_response_help'] = self::paragraph($this->copy->text('queue.nextStepAgencyResponseHelp', $values));
    }
    foreach ($fields as $key => $value) {
      $build['fields'][$key] = self::paragraph($this->copy->text('queue.' . $key, $values) . ': ' . $value);
    }
    foreach ([
      'agency_late' => 'agencyLate',
      'owner_unavailable' => 'ownerless',
      'invitation_lapsed' => 'invitationLapsed',
      'audit_pending' => 'auditPending',
    ] as $field => $key) {
      if (!empty($row[$field])) {
        $build['flags'][$field] = self::paragraph($this->copy->text('flag.' . $key, $values + [
          'date' => $this->date($row['next_action']['expected_by'] ?? NULL),
        ]));
      }
    }
    foreach (['closing_delivery' => 'closingSaved', 'notification' => 'noticeSaved'] as $field => $key) {
      if (!empty($row[$field])) {
        $build['flags'][$field] = self::paragraph($this->copy->text('flag.' . $key, $values + [
          'date' => $this->date($row[$field]['recorded_at'] ?? NULL),
        ]));
      }
    }
    if ($detail) {
      foreach (['pause_note' => 'pauseHeading', 'withdrawal_note' => 'stopHeading'] as $field => $key) {
        if (!empty($row[$field])) {
          $build['notes'][$field] = [
            'heading' => self::paragraph($this->copy->text('note.' . $key, $values), 'h2'),
            'note' => self::paragraph($row[$field]),
            'disclosure' => self::paragraph($this->copy->text('note.told', $values)),
          ];
        }
      }
    }
    foreach (self::actions($row) as $command => $label) {
      $build['actions'][$command] = [
        '#type' => 'link',
        '#title' => $this->copy->text('action.' . $label),
        '#url' => Url::fromRoute('agency_host.console_command', [
          'relationship' => $row['id'],
          'command' => $command,
        ]),
      ];
    }
    return $build;
  }

}
