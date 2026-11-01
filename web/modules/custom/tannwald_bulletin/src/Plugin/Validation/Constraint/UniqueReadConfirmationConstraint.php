<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Eine Lesebestätigung je Nutzer, Bulletin und Maschine.
 */
#[Constraint(
  id: 'UniqueReadConfirmation',
  label: new TranslatableMarkup('Unique read confirmation', [], ['context' => 'Validation']),
  type: 'entity:lesebestaetigung'
)]
class UniqueReadConfirmationConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    public string $message = 'This bulletin has already been confirmed for this machine.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
  }

}
