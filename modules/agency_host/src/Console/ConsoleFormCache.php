<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\Core\Form\FormCacheInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Rechecks private console forms before cached validation can render them.
 *
 * Core still owns cache-token validation and physical cache retention. Rejected
 * state is never imported into the live form; a fresh build supplies recovery.
 */
final class ConsoleFormCache implements FormCacheInterface {

  public function __construct(
    private readonly FormCacheInterface $inner,
    private readonly ConsoleApplication $application,
    private readonly ConsoleReviewStore $reviews,
    private readonly IdentitySelection $selection,
    private readonly RequestStack $requests,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCache($form_build_id, FormStateInterface $form_state) {
    $candidate = clone $form_state;
    $form = $this->inner->getCache($form_build_id, $candidate);
    if (!$form) {
      return $form;
    }
    $form_id = (string) ($form['form_id']['#value'] ?? '');
    if ($form_id === 'agency_host_console_held' || $form_id === 'chronicle_agency_console_held') {
      // A list can contain several names whose assignments changed separately.
      return NULL;
    }
    if (str_starts_with($form_id, 'agency_host_console_') || str_starts_with($form_id, 'chronicle_agency_console_')) {
      try {
        if (!$this->permitted($candidate)) {
          $this->inner->deleteCache($form_build_id);
          return NULL;
        }
      }
      catch (\Throwable) {
        // Failure cannot restore an old private form or grant a new command.
        return NULL;
      }
    }
    $form_state->setFormState($candidate->getCacheableArray());
    return $form;
  }

  /**
   * Validates only console cache state, never unrelated forms or domain writes.
   */
  private function permitted(FormStateInterface $state): bool {
    $request = $this->requests->getCurrentRequest();
    if (!$request || !$state->get('console_created')
      || $state->get('console_created') + 1800 <= $this->time->getCurrentTime()) {
      return FALSE;
    }
    $queue = $this->application->queue($request);
    if (($queue['outcome'] ?? '') !== 'available') {
      return FALSE;
    }
    $context = $state->get('relationship') ?? 'prepare';
    if ($context !== 'prepare' && !in_array($context, array_column($queue['contexts'], 'id'), TRUE)) {
      return FALSE;
    }
    foreach (['relationship', 'command'] as $attribute) {
      if ($request->attributes->has($attribute) && $request->attributes->get($attribute) !== $state->get($attribute)) {
        return FALSE;
      }
    }
    if ($nonce = $state->get('review_nonce')) {
      $record = $this->reviews->read($request, $nonce, $context, $state->get('command') ?? 'prepare');
      if ($record['attempted']) {
        // Results and pending status must always come from the fresh store.
        return FALSE;
      }
    }
    if ($selected = $state->get('selection')) {
      $result = $this->selection->apply($selected, $selected['email'], static fn(): array => ['outcome' => 'available']);
      if (($result['outcome'] ?? '') !== 'available') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setCache($form_build_id, $form, FormStateInterface $form_state) {
    $this->inner->setCache($form_build_id, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteCache($form_build_id) {
    $this->inner->deleteCache($form_build_id);
  }

}
