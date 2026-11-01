<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tannwald_bulletin\Entity\LesebestaetigungInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Prüft UniqueReadConfirmation gegen bestehende Zeilen.
 */
final class UniqueReadConfirmationConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof LesebestaetigungInterface || !$constraint instanceof UniqueReadConfirmationConstraint) {
      return;
    }
    $query = $this->entityTypeManager->getStorage('lesebestaetigung')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $value->getOwnerId())
      ->condition('bulletin_nid', $value->getBulletinId())
      ->condition('maschine_nid', $value->getMachineId())
      ->range(0, 1);
    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }
    if ($query->execute() !== []) {
      $this->context->buildViolation($constraint->message)->atPath('maschine_nid')->addViolation();
    }
  }

}
