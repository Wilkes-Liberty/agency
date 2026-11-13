<?php

declare(strict_types=1);

namespace Drupal\agency_recruiting\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a future close date before a posting may be published.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @Constraint(
 *   id = "AgencyPublishedCloseDate",
 *   label = @Translation("Published close date", context = "Validation"),
 *   type = "entity:agency_career_posting"
 * )
 */
#[Constraint(
  id: 'AgencyPublishedCloseDate',
  label: new TranslatableMarkup('Published close date', [], ['context' => 'Validation']),
  type: 'entity:agency_career_posting',
)]
final class PublishedCloseDateConstraint extends SymfonyConstraint {

  /**
   * Message when a published posting has no close date.
   */
  public string $missingCloseDate = 'A published posting requires a close date.';

  /**
   * Message when a published posting's close date is in the past.
   */
  public string $pastCloseDate = 'A published posting cannot have a past close date.';

}
