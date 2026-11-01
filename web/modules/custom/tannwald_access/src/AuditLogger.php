<?php

declare(strict_types=1);

namespace Drupal\tannwald_access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Schreibt das Audit-Log tannwald_audit (nur Insert).
 *
 * Ereignisse: download, zugriff_verweigert, freigabe, exportregel_geaendert,
 * lesebestaetigung (2.5). ip_land ist im PoC das field_land des handelnden
 * Nutzers (kein GeoIP).
 */
final class AuditLogger {

  /**
   * Tabellenname.
   */
  public const TABLE = 'tannwald_audit';

  /**
   * Bekannte Ereignisse.
   */
  public const EVENTS = ['download', 'zugriff_verweigert', 'freigabe', 'exportregel_geaendert', 'lesebestaetigung'];

  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Protokolliert ein Ereignis zu einer Entity.
   *
   * @param string $ereignis
   *   Eines von self::EVENTS.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Betroffene Entity.
   * @param array<string, mixed> $details
   *   Wird als JSON gespeichert.
   */
  public function log(string $ereignis, EntityInterface $entity, array $details = []): void {
    $this->logEntry($ereignis, $entity->getEntityTypeId(), (string) $entity->id(), $details);
  }

  /**
   * Protokolliert ein Ereignis ohne Entity-Objekt (z. B. Konfiguration).
   *
   * @param string $ereignis
   *   Eines von self::EVENTS.
   * @param string|null $entityType
   *   Entity-Typ oder "config".
   * @param string|null $entityId
   *   Entity-ID oder Konfigurationsname.
   * @param array<string, mixed> $details
   *   Wird als JSON gespeichert.
   */
  public function logEntry(string $ereignis, ?string $entityType, ?string $entityId, array $details = []): void {
    if (!in_array($ereignis, self::EVENTS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown audit event "%s".', $ereignis));
    }
    $uid = (int) $this->currentUser->id();
    $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'ereignis' => $ereignis,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'ip_land' => $this->countryOf($uid),
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();
  }

  /**
   * Land des Nutzers aus field_land.
   */
  private function countryOf(int $uid): ?string {
    if ($uid === 0) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof FieldableEntityInterface || !$user->hasField('field_land')) {
      return NULL;
    }
    $value = strtoupper(trim((string) $user->get('field_land')->value));
    return preg_match('/^[A-Z]{2}$/', $value) ? $value : NULL;
  }

}
