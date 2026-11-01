<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Vier-Augen-Prinzip: Sicherheits-Bulletins nicht durch die Erstellerin.
 */
#[Constraint(
  id: 'NotOwnBulletinApproval',
  label: new TranslatableMarkup('Safety bulletin not approved by its creator', [], ['context' => 'Validation']),
  type: 'entity:node'
)]
class NotOwnBulletinApprovalConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    public string $message = 'Safety bulletins must not be approved by the person who created them (four-eyes principle).',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
  }

}
