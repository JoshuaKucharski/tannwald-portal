<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Button „Gelesen bestätigen“ für die Komponente bulletin-banner (5.1).
 *
 * Sendet per POST an /bulletin/{node}/bestaetigen (Feld maschine, Form-Token
 * des Formulars, destination = aktuelle Seite).
 */
final class ConfirmForm extends FormBase {

  /**
   * Formular-ID.
   */
  public const FORM_ID = 'tannwald_bulletin_confirm_form';

  /**
   * Wert, gegen den Core das form_token dieses Formulars erzeugt.
   *
   * @see \Drupal\Core\Form\FormBuilder::prepareForm()
   */
  public static function tokenSeed(): string {
    return 'form_token_placeholder_' . Crypt::hashBase64(self::FORM_ID);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return self::FORM_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $bulletin = NULL, ?NodeInterface $maschine = NULL): array {
    if ($bulletin === NULL || $maschine === NULL) {
      return $form;
    }
    $form['#action'] = Url::fromRoute('tannwald_bulletin.confirm', ['node' => $bulletin->id()], [
      'query' => $this->getDestinationArray(),
    ])->toString();
    $form['#attributes']['class'][] = 'tannwald-bulletin-confirm';
    $form['maschine'] = ['#type' => 'hidden', '#value' => (string) $maschine->id()];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Confirm as read')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Wird nicht erreicht: das Formular sendet an tannwald_bulletin.confirm.
  }

}
