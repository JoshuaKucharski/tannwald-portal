<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\BulletinApprovalGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Prüft NotOwnBulletinApproval.
 */
final class NotOwnBulletinApprovalConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly BulletinApprovalGuard $guard,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tannwald_access.bulletin_approval_guard'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof NodeInterface || !$constraint instanceof NotOwnBulletinApprovalConstraint) {
      return;
    }
    if (!$this->guard->isOwnApproval($value, $this->currentUser)) {
      return;
    }
    $violation = $this->context->buildViolation($constraint->message);
    if ($value->hasField('moderation_state')) {
      $violation->atPath('moderation_state');
    }
    $violation->addViolation();
  }

}
