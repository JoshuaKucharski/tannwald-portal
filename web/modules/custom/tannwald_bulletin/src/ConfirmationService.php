<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\AuditLogger;
use Drupal\tannwald_bulletin\Entity\LesebestaetigungInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lesebestätigungen anlegen und abfragen (FA-DP-04, TC-05).
 */
final class ConfirmationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RecipientResolver $recipients,
    private readonly AuditLogger $audit,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Bestätigt ein Bulletin für eine Maschine; idempotent.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Wenn der Nutzer Bulletin oder Maschine nicht sieht oder das Bulletin die
   *   Maschine nicht betrifft.
   */
  public function confirm(NodeInterface $bulletin, NodeInterface $maschine, AccountInterface $account): LesebestaetigungInterface {
    if ($bulletin->bundle() !== 'bulletin' || $maschine->bundle() !== 'maschine') {
      throw new AccessDeniedHttpException('Wrong bundle.');
    }
    if ($account->isAnonymous() || !$bulletin->access('view', $account) || !$maschine->access('view', $account)) {
      throw new AccessDeniedHttpException('No access to bulletin or machine.');
    }
    if (!$this->concerns($bulletin, $maschine)) {
      throw new AccessDeniedHttpException('The bulletin does not concern this machine.');
    }
    $existing = $this->find((int) $bulletin->id(), (int) $maschine->id(), (int) $account->id());
    if ($existing !== NULL) {
      return $existing;
    }
    $storage = $this->entityTypeManager->getStorage('lesebestaetigung');
    /** @var \Drupal\tannwald_bulletin\Entity\LesebestaetigungInterface $confirmation */
    $confirmation = $storage->create([
      'uid' => $account->id(),
      'bulletin_nid' => $bulletin->id(),
      'maschine_nid' => $maschine->id(),
      'bestaetigt_am' => $this->time->getCurrentTime(),
      'ip_land' => $this->countryOf($account),
    ]);
    try {
      $confirmation->save();
    }
    catch (EntityStorageException $exception) {
      // Gleichzeitige zweite Bestätigung: der Unique-Index hat gegriffen.
      if (!$exception->getPrevious() instanceof IntegrityConstraintViolationException) {
        throw $exception;
      }
      $existing = $this->find((int) $bulletin->id(), (int) $maschine->id(), (int) $account->id());
      if ($existing !== NULL) {
        return $existing;
      }
      throw new \RuntimeException('Read confirmation could not be stored.');
    }
    $this->audit->log('lesebestaetigung', $confirmation, [
      'bulletin_nid' => (int) $bulletin->id(),
      'maschine_nid' => (int) $maschine->id(),
    ]);
    return $confirmation;
  }

  /**
   * Zeitpunkt einer Bestätigung oder NULL.
   */
  public function isConfirmed(int $bulletinNid, int $maschineNid, int $uid): ?int {
    return $this->find($bulletinNid, $maschineNid, $uid)?->getConfirmedTime();
  }

  /**
   * Letzte Bestätigung eines Nutzers für ein Bulletin (für 5.1 und 4.2).
   *
   * @return array{bestaetigt_am: int, maschine_nid: int, uid: int}|null
   *   Unix-Zeit, Maschine und Nutzer oder NULL.
   */
  public function confirmedAt(NodeInterface $bulletin, AccountInterface $account): ?array {
    $ids = $this->entityTypeManager->getStorage('lesebestaetigung')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bulletin_nid', $bulletin->id())
      ->condition('uid', $account->id())
      ->sort('bestaetigt_am', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $confirmation = $this->entityTypeManager->getStorage('lesebestaetigung')->load(reset($ids));
    if (!$confirmation instanceof LesebestaetigungInterface) {
      return NULL;
    }
    return [
      'bestaetigt_am' => $confirmation->getConfirmedTime(),
      'maschine_nid' => $confirmation->getMachineId(),
      'uid' => (int) $confirmation->getOwnerId(),
    ];
  }

  /**
   * Betroffene Nutzer mit noch unbestätigten Maschinen.
   *
   * @return array<int, array{account: \Drupal\user\UserInterface, maschinen: \Drupal\node\NodeInterface[]}>
   *   Nach uid; nur Maschinen ohne Bestätigung, nur Nutzer mit mindestens
   *   einer.
   */
  public function openConfirmations(NodeInterface $bulletin): array {
    $open = [];
    foreach ($this->recipients->forBulletin($bulletin) as $uid => $recipient) {
      $machines = array_values(array_filter(
        $recipient['maschinen'],
        fn(NodeInterface $machine): bool => $this->find((int) $bulletin->id(), (int) $machine->id(), $uid) === NULL,
      ));
      if ($machines !== []) {
        $open[$uid] = ['account' => $recipient['account'], 'maschinen' => $machines];
      }
    }
    return $open;
  }

  /**
   * Ob das Bulletin die Maschine betrifft (Typ oder Seriennummer).
   */
  public function concerns(NodeInterface $bulletin, NodeInterface $maschine): bool {
    $serials = $this->targetIds($bulletin, 'field_seriennummern');
    if (in_array((int) $maschine->id(), $serials, TRUE)) {
      return TRUE;
    }
    return array_intersect($this->targetIds($bulletin, 'field_maschinentyp'), $this->targetIds($maschine, 'field_maschinentyp')) !== [];
  }

  /**
   * Bestehende Bestätigung.
   */
  private function find(int $bulletinNid, int $maschineNid, int $uid): ?LesebestaetigungInterface {
    $storage = $this->entityTypeManager->getStorage('lesebestaetigung');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('bulletin_nid', $bulletinNid)
      ->condition('maschine_nid', $maschineNid)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $confirmation = $storage->load(reset($ids));
    return $confirmation instanceof LesebestaetigungInterface ? $confirmation : NULL;
  }

  /**
   * Land des Nutzers aus field_land (wie Audit; kein GeoIP).
   */
  private function countryOf(AccountInterface $account): ?string {
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof FieldableEntityInterface || !$user->hasField('field_land')) {
      return NULL;
    }
    $value = strtoupper(trim((string) $user->get('field_land')->value));
    return preg_match('/^[A-Z]{2}$/', $value) ? $value : NULL;
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
