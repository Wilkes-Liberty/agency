<?php

declare(strict_types=1);

namespace Drupal\agency_recruiting\Plugin\Validation\Constraint;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\agency_recruiting\Entity\CareerPosting;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Refuses published postings with no close date or a past close date.
 */
final class PublishedCloseDateConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof CareerPosting || !$constraint instanceof PublishedCloseDateConstraint) {
      return;
    }
    if ((string) $value->get('status')->value !== 'published') {
      return;
    }
    $close = $value->get('valid_through')->value;
    if ($close === NULL || $close === '' || (int) $close === 0) {
      $this->context->addViolation($constraint->missingCloseDate);
      return;
    }
    if ((int) $close <= $this->time->getRequestTime()) {
      $this->context->addViolation($constraint->pastCloseDate);
    }
  }

}
