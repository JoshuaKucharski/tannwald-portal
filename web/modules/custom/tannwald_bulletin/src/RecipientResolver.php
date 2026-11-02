<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\EntitlementService;

/**
 * Betroffene Nutzer eines Bulletins (Empfänger der Mail, Basis "offen").
 *
 * Regel: aktive Nutzer mit Rolle kunde oder haendler, deren Keys
 * (EntitlementService::getRealmsForAccount) einen type:- oder serial:-Key des
 * Bulletins enthalten. Damit gelten dieselben Regeln wie für die Sicht
 * (Händler über ihr Gebiet, keine Seriennummern für Händler). Interne Rollen
 * erhalten keine Mail. Kosten: O(Nutzer mit Rolle kunde/haendler), je Nutzer
 * eine (gecachte) Key-Berechnung und eine Maschinen-Abfrage.
 */
final class RecipientResolver {

  /**
   * Rollen, die Bulletins zugestellt bekommen.
   */
  public const RECIPIENT_ROLES = ['kunde', 'haendler'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementService $entitlement,
  ) {}

  /**
   * Empfänger mit ihren betroffenen Maschinen.
   *
   * @return array<int, array{account: \Drupal\user\UserInterface, maschinen: \Drupal\node\NodeInterface[]}>
   *   Nach uid; Maschinen nach Titel (Seriennummer) sortiert.
   */
  public function forBulletin(NodeInterface $bulletin): array {
    if ($bulletin->bundle() !== 'bulletin') {
      return [];
    }
    $bulletinKeys = array_values(array_filter(
      $this->entitlement->getRealmsForNode($bulletin),
      static fn(string $key): bool => str_starts_with($key, 'type:') || str_starts_with($key, 'serial:'),
    ));
    if ($bulletinKeys === []) {
      return [];
    }
    $typeIds = $this->targetIds($bulletin, 'field_maschinentyp');
    $serialIds = $this->targetIds($bulletin, 'field_seriennummern');

    $userStorage = $this->entityTypeManager->getStorage('user');
    $uids = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', self::RECIPIENT_ROLES, 'IN')
      ->sort('uid')
      ->execute();

    $recipients = [];
    /** @var \Drupal\user\UserInterface[] $accounts */
    $accounts = $userStorage->loadMultiple($uids);
    foreach ($accounts as $account) {
      $accountKeys = $this->entitlement->getRealmsForAccount($account);
      if (array_intersect($bulletinKeys, $accountKeys) === []) {
        continue;
      }
      $machines = $this->affectedMachines($accountKeys, $typeIds, $serialIds);
      if ($machines === []) {
        continue;
      }
      $recipients[(int) $account->id()] = ['account' => $account, 'maschinen' => $machines];
    }
    return $recipients;
  }

  /**
   * Maschinen, die der Nutzer sieht (firma:-Keys) und das Bulletin betrifft.
   *
   * @param string[] $accountKeys
   *   Keys des Nutzers.
   * @param int[] $typeIds
   *   Term-IDs aus field_maschinentyp des Bulletins.
   * @param int[] $serialIds
   *   Maschinen-NIDs aus field_seriennummern des Bulletins.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Maschinen nach Titel sortiert.
   */
  private function affectedMachines(array $accountKeys, array $typeIds, array $serialIds): array {
    $companyIds = [];
    foreach ($accountKeys as $key) {
      if (preg_match('/^firma:(\d+)$/', $key, $match)) {
        $companyIds[] = (int) $match[1];
      }
    }
    if ($companyIds === [] || ($typeIds === [] && $serialIds === [])) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'maschine')
      ->condition('field_firma', $companyIds, 'IN')
      ->sort('title');
    $affected = $query->orConditionGroup();
    if ($typeIds !== []) {
      $affected->condition('field_maschinentyp', $typeIds, 'IN');
    }
    if ($serialIds !== []) {
      $affected->condition('nid', $serialIds, 'IN');
    }
    $ids = $query->condition($affected)->execute();
    /** @var \Drupal\node\NodeInterface[] $machines */
    $machines = $ids === [] ? [] : array_values($storage->loadMultiple($ids));
    return $machines;
  }

  /**
   * Referenz-IDs eines Feldes.
   *
   * @return int[]
   *   IDs.
   */
  private function targetIds(NodeInterface $node, string $fieldName): array {
    if (!$node->hasField($fieldName)) {
      return [];
    }
    return array_values(array_filter(array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $node->get($fieldName)->getValue(),
    )));
  }

}
