<?php

declare(strict_types=1);

namespace Drupal\tannwald_access;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Liest die Exportregeln aus tannwald_access.settings.
 *
 * Schlüssel nach Pflichtenheft: export_rules.<klasse>.laender (ISO alpha-2)
 * und export_rules.<klasse>.gesperrte_firmen (field_sap_nr der Firma).
 */
final class ExportRuleService {

  /**
   * Konfigurationsname.
   */
  public const CONFIG = 'tannwald_access.settings';

  /**
   * Exportklassen mit Regeln; "keine" braucht keine Regel.
   */
  public const RULE_CLASSES = ['klasse_1', 'klasse_2'];

  /**
   * Alle Werte von field_exportklasse.
   */
  public const ALL_CLASSES = ['keine', 'klasse_1', 'klasse_2'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Erlaubte Länder einer Exportklasse.
   *
   * @return string[]
   *   ISO-3166 alpha-2 Codes; unbekannte Klasse ergibt eine leere Liste.
   */
  public function allowedCountries(string $klasse): array {
    return $this->listFor($klasse, 'laender');
  }

  /**
   * Gesperrte Firmen einer Exportklasse.
   *
   * @return string[]
   *   SAP-Nummern; unbekannte Klasse ergibt eine leere Liste.
   */
  public function blockedCompanies(string $klasse): array {
    return $this->listFor($klasse, 'gesperrte_firmen');
  }

  /**
   * Prüft, ob ein Land und eine Firma eine Exportklasse erhalten.
   */
  public function isAllowed(string $klasse, ?string $country, ?string $sapNumber): bool {
    if ($klasse === 'keine') {
      return TRUE;
    }
    if ($country === NULL || $country === '') {
      // Ohne Land keine Freigabe (fail closed).
      return FALSE;
    }
    if (!in_array($country, $this->allowedCountries($klasse), TRUE)) {
      return FALSE;
    }
    return $sapNumber === NULL || $sapNumber === '' || !in_array($sapNumber, $this->blockedCompanies($klasse), TRUE);
  }

  /**
   * Liest eine Liste aus der Konfiguration.
   *
   * @return string[]
   *   Die Werte als Strings, ohne Leerwerte.
   */
  private function listFor(string $klasse, string $key): array {
    if (!in_array($klasse, self::RULE_CLASSES, TRUE)) {
      return [];
    }
    $value = $this->configFactory->get(self::CONFIG)->get("export_rules.$klasse.$key");
    if (!is_array($value)) {
      return [];
    }
    $values = array_map('strval', $value);
    return array_values(array_filter($values, static fn(string $item): bool => $item !== ''));
  }

}
