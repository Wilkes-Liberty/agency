<?php

declare(strict_types=1);

namespace Drupal\agency_host\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\externalauth\Form\AuthmapDeleteForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Preserves admin confirmation while using tracked public deletion.
 */
final class TrackedAuthmapDeleteForm extends AuthmapDeleteForm {

  public function __construct(Connection $database, EntityTypeManagerInterface $entities, private readonly AuthmapInterface $authmap) {
    parent::__construct($database, $entities);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'), $container->get('entity_type.manager'), $container->get('externalauth.authmap'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $provider = $this->getRouteMatch()->getParameter('provider');
    $uid = $this->getRouteMatch()->getParameter('uid');
    if (!is_string($provider) || $provider === '' || !$uid || filter_var($uid, FILTER_VALIDATE_INT) === FALSE) {
      throw new \LogicException('A valid provider and account are required.');
    }
    $this->authmap->delete((int) $uid, $provider);
    $this->messenger()->addStatus($this->t('The link has been deleted.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
