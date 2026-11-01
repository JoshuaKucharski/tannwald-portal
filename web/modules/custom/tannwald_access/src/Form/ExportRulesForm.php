<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tannwald_access\AuditLogger;
use Drupal\tannwald_access\ExportRuleService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formular /compliance/exportregeln.
 */
final class ExportRulesForm extends ConfigFormBase {

  /**
   * Audit-Logger.
   */
  protected AuditLogger $audit;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->audit = $container->get('tannwald_access.audit');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tannwald_access_export_rules';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ExportRuleService::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ExportRuleService::CONFIG);
    $labels = [
      'klasse_1' => $this->t('Export class 1'),
      'klasse_2' => $this->t('Export class 2 (dual use)'),
    ];
    foreach (ExportRuleService::RULE_CLASSES as $exportClass) {
      $form[$exportClass] = [
        '#type' => 'details',
        '#title' => $labels[$exportClass],
        '#open' => TRUE,
      ];
      $form[$exportClass][$exportClass . '_countries'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Allowed countries'),
        '#description' => $this->t('ISO 3166-1 alpha-2 codes in capital letters, separated by commas, e.g. CH, DE, AT.'),
        '#default_value' => implode(', ', (array) $config->get("export_rules.$exportClass.laender")),
        '#maxlength' => 1024,
      ];
      $form[$exportClass][$exportClass . '_blocked'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Blocked companies'),
        '#description' => $this->t('SAP numbers, separated by commas.'),
        '#default_value' => implode(', ', (array) $config->get("export_rules.$exportClass.gesperrte_firmen")),
        '#maxlength' => 1024,
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (ExportRuleService::RULE_CLASSES as $exportClass) {
      $name = $exportClass . '_countries';
      foreach (self::splitList((string) $form_state->getValue($name)) as $code) {
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
          $form_state->setErrorByName($name, $this->t('%code is not an ISO 3166-1 alpha-2 code in capital letters.', ['%code' => $code]));
        }
      }
      foreach (self::splitList((string) $form_state->getValue($exportClass . '_blocked')) as $sapNumber) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $sapNumber)) {
          $form_state->setErrorByName($exportClass . '_blocked', $this->t('%value is not a valid SAP number.', ['%value' => $sapNumber]));
        }
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ExportRuleService::CONFIG);
    $before = (array) $config->get('export_rules');
    $after = [];
    foreach (ExportRuleService::RULE_CLASSES as $exportClass) {
      $after[$exportClass] = [
        'laender' => array_values(array_unique(self::splitList((string) $form_state->getValue($exportClass . '_countries')))),
        'gesperrte_firmen' => array_values(array_unique(self::splitList((string) $form_state->getValue($exportClass . '_blocked')))),
      ];
    }
    // Speichern invalidiert config:tannwald_access.settings und damit den
    // Cache der Nutzer-Keys. Die Records in node_access hängen nicht an den
    // Exportregeln (die Klasse steckt in den Nutzer-Keys); ein Neuaufbau ist
    // darum nicht nötig (Entscheid Master, 2.4).
    $config->set('export_rules', $after)->save();
    $this->audit->logEntry('exportregel_geaendert', 'config', ExportRuleService::CONFIG, [
      'vorher' => $before,
      'nachher' => $after,
    ]);
    // Ohne parent::submitForm(): eigene Meldung statt der Standardmeldung.
    $this->messenger()->addStatus($this->t('Export rules saved; they take effect immediately.'));
  }

  /**
   * Zerlegt eine kommagetrennte Liste.
   *
   * @return string[]
   *   Getrimmte, nicht leere Werte.
   */
  private static function splitList(string $value): array {
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
  }

}
