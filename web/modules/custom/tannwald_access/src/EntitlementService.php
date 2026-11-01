<?php

declare(strict_types=1);

namespace Drupal\tannwald_access;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Berechnet die Access-Keys (Realms) für Nutzer und Nodes.
 *
 * Eine Quelle für drei Verbraucher: hook_node_grants / hook_node_access_records
 * (über grantForKey()), access_keys im Chunk-Store (D, 3.1) und den Filter des
 * Retrievers (E, 4.1).
 *
 * Key-Format (Pflichtenheft §5.5, Master-Präzisierung 2.4): Drupal-Grants und
 * access_keys sind ODER-verknüpft. Damit "Typ oder Serial oder intern" UND
 * "Export erlaubt" als eine Schnittmenge funktioniert, trägt jeder Key beide
 * Bedingungen: "<basis>+export:<klasse>" mit basis ∈ {type:<code>,
 * serial:<nid>, role:intern} und klasse ∈ {keine, klasse_1, klasse_2}.
 * Ein Dokument trägt je Basis genau seine Klasse; ein Nutzer trägt je Basis
 * immer "keine" und zusätzlich jede Klasse, die ihm die Exportregeln erlauben.
 * Sichtbar ist, was eine nicht leere Schnittmenge hat.
 *
 * Maschinen (Anpassung 11) tragen "firma:<tid>" und "role:intern" ohne
 * Export-Teil; ein Nutzer erhält dieselben Keys für die Firmen, deren
 * Maschinen er sehen darf.
 */
final class EntitlementService {

  /**
   * Rollen mit role:intern.
   */
  public const INTERN_ROLES = ['techniker', 'redaktion', 'compliance', 'portal_admin'];

  /**
   * Basis-Key der internen Rollen.
   */
  public const KEY_INTERN = 'role:intern';

  /**
   * Trenner zwischen Basis und Export-Teil.
   */
  public const EXPORT_GLUE = '+export:';

  /**
   * Realm-Präfix aller Grants dieses Moduls.
   */
  public const REALM_PREFIX = 'tannwald_';

  /**
   * Realm des Sperr-Records: hält geschützte Bundles ohne Key verschlossen.
   */
  public const REALM_CLOSED = 'tannwald_closed';

  /**
   * Bundles, deren Sichtbarkeit dieses Modul bestimmt.
   */
  public const PROTECTED_BUNDLES = ['dokument', 'bulletin', 'maschine', 'zusammenfassung'];

  /**
   * Realm für unveröffentlichte Workflow-Inhalte (6.1, Nachtrag 1 zu 2.4).
   *
   * Core schreibt für unveröffentlichte Nodes keinen Grant; ohne Record sind
   * Entwürfe und „Zur Prüfung“ in Views und Entity-Queries mit Access-Check
   * auch für Konten mit "view any unpublished content" unsichtbar.
   */
  public const REALM_UNPUBLISHED = 'tannwald_unpublished';

  /**
   * Bundles mit Workflow „redaktion“.
   */
  public const WORKFLOW_BUNDLES = ['dokument', 'bulletin', 'zusammenfassung'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ExportRuleService $exportRules,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Keys eines Nutzers, sortiert und eindeutig.
   *
   * @return string[]
   *   Zum Beispiel ["firma:12", "role:intern",
   *   "type:TB200+export:keine", "serial:41+export:klasse_2"].
   */
  public function getRealmsForAccount(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid === 0) {
      // Anonyme Nutzer haben keine Keys.
      return [];
    }
    $cid = 'tannwald_access:account_keys:' . $uid;
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }
    $keys = $this->computeAccountKeys($uid);
    $this->cache->set($cid, $keys, CacheBackendInterface::CACHE_PERMANENT, $this->accountCacheTags($uid));
    return $keys;
  }

  /**
   * Cache-Tags der Nutzer-Keys.
   *
   * @return string[]
   *   Tags, die eine Neuberechnung auslösen.
   */
  public function accountCacheTags(int $uid): array {
    return [
      'user:' . $uid,
      'taxonomy_term_list:firma',
      'taxonomy_term_list:maschinentyp',
      'node_list:maschine',
      'config:' . ExportRuleService::CONFIG,
    ];
  }

  /**
   * Keys eines Nodes (der übergebenen Übersetzung), sortiert und eindeutig.
   *
   * Unabhängig vom Veröffentlichungsstatus: ob Records geschrieben werden,
   * entscheidet getAccessRecordsForNode(); der Chunk-Store indexiert nur
   * veröffentlichte Revisionen.
   *
   * @return string[]
   *   Zum Beispiel ["role:intern+export:klasse_2",
   *   "type:TB200+export:klasse_2"]; leer für fremde Bundles.
   */
  public function getRealmsForNode(NodeInterface $node): array {
    $keys = match ($node->bundle()) {
      'dokument' => $this->documentKeys($node),
      'bulletin' => $this->bulletinKeys($node),
      'maschine' => $this->machineKeys($node),
      'zusammenfassung' => $this->summaryKeys($node),
      default => [],
    };
    $keys = array_values(array_unique($keys));
    sort($keys);
    return $keys;
  }

  /**
   * Grants eines Nutzers für hook_node_grants().
   *
   * @return array<string, int[]>
   *   Realm => gids.
   */
  public function getGrantsForAccount(AccountInterface $account): array {
    $grants = [];
    $map = $this->typeCodeMap();
    foreach ($this->getRealmsForAccount($account) as $key) {
      $grant = $this->mapKey($key, $map);
      if ($grant !== NULL) {
        $grants[$grant[0]][] = $grant[1];
      }
    }
    foreach ($grants as $realm => $gids) {
      $grants[$realm] = array_values(array_unique($gids));
    }
    if ($account->hasPermission('view any unpublished content')) {
      $grants[self::REALM_UNPUBLISHED] = [1];
    }
    return $grants;
  }

  /**
   * Records eines Nodes für hook_node_access_records().
   *
   * Je veröffentlichter Übersetzung ein View-Record pro Key; geschützte
   * Bundles ohne Record erhalten einen Sperr-Record, damit Core nicht den
   * Standard-Grant "all" schreibt.
   *
   * @return array<int, array<string, int|string>>
   *   Records mit realm, gid, grant_view, grant_update, grant_delete, langcode.
   */
  public function getAccessRecordsForNode(NodeInterface $node): array {
    if (!in_array($node->bundle(), self::PROTECTED_BUNDLES, TRUE)) {
      return [];
    }
    $records = [];
    $map = $this->typeCodeMap();
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      if (!$translation->isPublished()) {
        if (in_array($node->bundle(), self::WORKFLOW_BUNDLES, TRUE)) {
          $records[] = [
            'realm' => self::REALM_UNPUBLISHED,
            'gid' => 1,
            'grant_view' => 1,
            'grant_update' => 0,
            'grant_delete' => 0,
            'langcode' => $langcode,
          ];
        }
        continue;
      }
      foreach ($this->getRealmsForNode($translation) as $key) {
        $grant = $this->mapKey($key, $map);
        if ($grant === NULL) {
          continue;
        }
        $records[] = [
          'realm' => $grant[0],
          'gid' => $grant[1],
          'grant_view' => 1,
          'grant_update' => 0,
          'grant_delete' => 0,
          'langcode' => $langcode,
        ];
      }
    }
    if ($records === []) {
      // Core schreibt nur Records mit mindestens einem Recht; dieser Record
      // bleibt ungeschrieben und verhindert nur den Standard-Grant.
      $records[] = [
        'realm' => self::REALM_CLOSED,
        'gid' => 0,
        'grant_view' => 0,
        'grant_update' => 0,
        'grant_delete' => 0,
      ];
    }
    return $records;
  }

  /**
   * Bildet einen Key auf Realm und gid ab.
   *
   * @return array{0: string, 1: int}|null
   *   [realm, gid] oder NULL, wenn der Key nicht abbildbar ist.
   */
  public function grantForKey(string $key): ?array {
    return $this->mapKey($key, $this->typeCodeMap());
  }

  /**
   * Bildet einen Key mit einer gegebenen Typ-Code-Tabelle ab.
   *
   * @param string $key
   *   Der Key.
   * @param array<string, int> $map
   *   typ_code => Term-ID.
   *
   * @return array{0: string, 1: int}|null
   *   [realm, gid] oder NULL.
   */
  private function mapKey(string $key, array $map): ?array {
    if ($key === self::KEY_INTERN) {
      return [self::REALM_PREFIX . 'intern', 1];
    }
    if (preg_match('/^firma:(\d+)$/', $key, $match)) {
      return [self::REALM_PREFIX . 'firma', (int) $match[1]];
    }
    if (!preg_match('/^(type|serial|role):([^+]+)\+export:([a-z0-9_]+)$/', $key, $match)) {
      return NULL;
    }
    [, $kind, $value, $exportClass] = $match;
    $gid = match ($kind) {
      'type' => $map[$value] ?? NULL,
      'serial' => ctype_digit($value) ? (int) $value : NULL,
      default => $value === 'intern' ? 1 : NULL,
    };
    if ($gid === NULL) {
      return NULL;
    }
    $realmKind = $kind === 'role' ? 'intern' : $kind;
    return [self::REALM_PREFIX . $realmKind . '_' . $exportClass, $gid];
  }

  /**
   * Berechnet die Keys eines Nutzers ohne Cache.
   *
   * @return string[]
   *   Sortiert und eindeutig.
   */
  private function computeAccountKeys(int $uid): array {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof FieldableEntityInterface) {
      return [];
    }
    /** @var \Drupal\user\UserInterface $user */
    $roles = $user->getRoles(TRUE);
    $country = $this->stringValue($user, 'field_land');
    $country = $country === NULL ? NULL : strtoupper($country);
    $companyId = $this->targetIds($user, 'field_firma')[0] ?? NULL;
    $company = $companyId === NULL ? NULL : $this->entityTypeManager->getStorage('taxonomy_term')->load($companyId);
    $sapNumber = $company instanceof FieldableEntityInterface ? $this->stringValue($company, 'field_sap_nr') : NULL;

    $bases = [];
    $machineCompanies = [];

    if (in_array('kunde', $roles, TRUE) && $companyId !== NULL) {
      // Kunde: Typen und Seriennummern der Maschinen der eigenen Firma.
      $machineCompanies[] = $companyId;
      foreach ($this->machinesOf([$companyId]) as $machine) {
        $bases[] = 'serial:' . $machine->id();
        foreach ($this->typeCodes($this->targetIds($machine, 'field_maschinentyp')) as $code) {
          $bases[] = 'type:' . $code;
        }
      }
    }

    if (in_array('haendler', $roles, TRUE) && $company instanceof FieldableEntityInterface) {
      // Händler: Typen im Bestand der Kundenfirmen, deren Land im Gebiet der
      // Händlerfirma liegt (Master-Entscheid 2.4). Keine Seriennummern:
      // anlagenspezifische Dokumente bleiben der besitzenden Firma (FA-DP-09).
      $customerIds = $this->customersInTerritory($this->stringValues($company, 'field_haendler_gebiet'));
      $machineCompanies = array_merge($machineCompanies, $customerIds);
      foreach ($this->machinesOf($customerIds) as $machine) {
        foreach ($this->typeCodes($this->targetIds($machine, 'field_maschinentyp')) as $code) {
          $bases[] = 'type:' . $code;
        }
      }
    }

    $isIntern = array_intersect(self::INTERN_ROLES, $roles) !== [];
    if ($isIntern) {
      $bases[] = self::KEY_INTERN;
    }

    $keys = [];
    foreach (array_unique($bases) as $base) {
      foreach (ExportRuleService::ALL_CLASSES as $exportClass) {
        // Exportregeln gelten auch für role:intern (Pflichtenheft §5.5).
        if ($this->exportRules->isAllowed($exportClass, $country, $sapNumber)) {
          $keys[] = $base . self::EXPORT_GLUE . $exportClass;
        }
      }
    }
    foreach (array_unique($machineCompanies) as $tid) {
      $keys[] = 'firma:' . $tid;
    }
    if ($isIntern) {
      $keys[] = self::KEY_INTERN;
    }
    $keys = array_values(array_unique($keys));
    sort($keys);
    return $keys;
  }

  /**
   * Keys eines Dokuments.
   *
   * @return string[]
   *   Unsortiert.
   */
  private function documentKeys(NodeInterface $node): array {
    $exportClass = $this->stringValue($node, 'field_exportklasse') ?? 'keine';
    $serials = $this->targetIds($node, 'field_seriennummer');
    $bases = [];
    if ($serials !== []) {
      // Anlagenspezifisch: nur der Serial-Treffer zählt, type: genügt nicht
      // (FA-DP-09).
      foreach ($serials as $nid) {
        $bases[] = 'serial:' . $nid;
      }
    }
    else {
      foreach ($this->typeCodes($this->targetIds($node, 'field_maschinentyp')) as $code) {
        $bases[] = 'type:' . $code;
      }
    }
    $bases[] = self::KEY_INTERN;
    return $this->combine($bases, $exportClass);
  }

  /**
   * Keys eines Bulletins.
   *
   * Wie ein Dokument mit Exportklasse "keine", dazu serial:<nid> je
   * field_seriennummern.
   *
   * @return string[]
   *   Unsortiert.
   */
  private function bulletinKeys(NodeInterface $node): array {
    $bases = [];
    foreach ($this->typeCodes($this->targetIds($node, 'field_maschinentyp')) as $code) {
      $bases[] = 'type:' . $code;
    }
    foreach ($this->targetIds($node, 'field_seriennummern') as $nid) {
      $bases[] = 'serial:' . $nid;
    }
    $bases[] = self::KEY_INTERN;
    return $this->combine($bases, 'keine');
  }

  /**
   * Keys einer Maschine: firma:<tid> der besitzenden Firma und role:intern.
   *
   * @return string[]
   *   Unsortiert.
   */
  private function machineKeys(NodeInterface $node): array {
    $keys = [];
    foreach ($this->targetIds($node, 'field_firma') as $tid) {
      $keys[] = 'firma:' . $tid;
    }
    $keys[] = self::KEY_INTERN;
    return $keys;
  }

  /**
   * Keys einer Zusammenfassung: die Keys des referenzierten Dokuments.
   *
   * Ohne Dokument keine Keys (fail closed).
   *
   * @return string[]
   *   Unsortiert.
   */
  private function summaryKeys(NodeInterface $node): array {
    $documentId = $this->targetIds($node, 'field_dokument')[0] ?? NULL;
    if ($documentId === NULL) {
      return [];
    }
    $document = $this->entityTypeManager->getStorage('node')->load($documentId);
    if (!$document instanceof NodeInterface || $document->bundle() !== 'dokument') {
      return [];
    }
    return $this->documentKeys($document);
  }

  /**
   * Hängt den Export-Teil an jede Basis.
   *
   * @param string[] $bases
   *   Basis-Keys.
   * @param string $exportClass
   *   Exportklasse.
   *
   * @return string[]
   *   Kombinierte Keys.
   */
  private function combine(array $bases, string $exportClass): array {
    return array_map(static fn(string $base): string => $base . self::EXPORT_GLUE . $exportClass, array_unique($bases));
  }

  /**
   * Maschinen der gegebenen Firmen.
   *
   * @param int[] $companyIds
   *   Term-IDs aus dem Vokabular firma.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Maschinen-Nodes.
   */
  private function machinesOf(array $companyIds): array {
    if ($companyIds === [] || !$this->hasField('node', 'maschine', 'field_firma')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'maschine')
      ->condition('field_firma', $companyIds, 'IN')
      ->execute();
    /** @var \Drupal\node\NodeInterface[] $machines */
    $machines = $ids === [] ? [] : $storage->loadMultiple($ids);
    return $machines;
  }

  /**
   * Kundenfirmen, deren Land in den gegebenen Ländern liegt.
   *
   * @param string[] $countries
   *   ISO-Codes des Händlergebiets.
   *
   * @return int[]
   *   Term-IDs.
   */
  private function customersInTerritory(array $countries): array {
    $countries = array_values(array_filter(array_map('strtoupper', $countries)));
    if ($countries === [] || !$this->hasField('taxonomy_term', 'firma', 'field_land') || !$this->hasField('taxonomy_term', 'firma', 'field_firma_art')) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'firma')
      ->condition('field_firma_art', 'kunde')
      ->condition('field_land', $countries, 'IN')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Typ-Codes zu Term-IDs aus dem Vokabular maschinentyp.
   *
   * @param int[] $termIds
   *   Term-IDs.
   *
   * @return string[]
   *   Codes wie TB200; Terme ohne Code fallen weg.
   */
  private function typeCodes(array $termIds): array {
    if ($termIds === []) {
      return [];
    }
    $codes = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($termIds) as $term) {
      if ($term->bundle() === 'maschinentyp') {
        $code = $this->stringValue($term, 'field_typ_code');
        if ($code !== NULL) {
          $codes[] = $code;
        }
      }
    }
    return $codes;
  }

  /**
   * Tabelle typ_code => kleinste Term-ID (gid der type:-Realms).
   *
   * @return array<string, int>
   *   Code => Term-ID.
   */
  private function typeCodeMap(): array {
    $cid = 'tannwald_access:type_code_map';
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }
    $map = [];
    if ($this->hasField('taxonomy_term', 'maschinentyp', 'field_typ_code')) {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('vid', 'maschinentyp')->sort('tid')->execute();
      foreach ($storage->loadMultiple($ids) as $term) {
        $code = $this->stringValue($term, 'field_typ_code');
        if ($code !== NULL && !isset($map[$code])) {
          $map[$code] = (int) $term->id();
        }
      }
    }
    $this->cache->set($cid, $map, CacheBackendInterface::CACHE_PERMANENT, ['taxonomy_term_list:maschinentyp']);
    return $map;
  }

  /**
   * Prüft, ob ein Bundle ein Feld hat.
   */
  private function hasField(string $entityTypeId, string $bundle, string $fieldName): bool {
    return isset($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle)[$fieldName]);
  }

  /**
   * Referenz-IDs eines Feldes.
   *
   * @return int[]
   *   IDs, leer wenn das Feld fehlt.
   */
  private function targetIds(FieldableEntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName)) {
      return [];
    }
    $ids = [];
    foreach ($entity->get($fieldName)->getValue() as $item) {
      if (isset($item['target_id']) && $item['target_id'] !== '') {
        $ids[] = (int) $item['target_id'];
      }
    }
    return $ids;
  }

  /**
   * Erster Wert eines einfachen Feldes, getrimmt.
   */
  private function stringValue(FieldableEntityInterface $entity, string $fieldName): ?string {
    return $this->stringValues($entity, $fieldName)[0] ?? NULL;
  }

  /**
   * Alle Werte eines einfachen Feldes, getrimmt, ohne Leerwerte.
   *
   * @return string[]
   *   Werte.
   */
  private function stringValues(FieldableEntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName)) {
      return [];
    }
    $values = [];
    foreach ($entity->get($fieldName)->getValue() as $item) {
      $value = trim((string) ($item['value'] ?? ''));
      if ($value !== '') {
        $values[] = $value;
      }
    }
    return $values;
  }

}
