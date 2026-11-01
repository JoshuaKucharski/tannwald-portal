<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Review\ReviewTransition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review-Karte: Textarea body und je erlaubter Transition ein Button.
 *
 * Jeder Button postet per formaction direkt an die Route
 * tannwald_ingest.review_transition (ein Endpunkt für Formular und JSON); der
 * Form-Token des Formulars ist der CSRF-Schutz.
 */
final class ReviewForm extends FormBase {

  /**
   * Formular-ID (zugleich Schlüssel des Form-Tokens).
   */
  public const FORM_ID = 'tannwald_ingest_review_form';

  public function __construct(
    protected ReviewTransition $reviewTransition,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('tannwald_ingest.review_transition'));
  }

  /**
   * Schlüssel, mit dem FormBuilder den Form-Token dieses Formulars erzeugt.
   *
   * Wie FormBuilder::prepareForm(): Platzhalter aus der Formular-ID.
   */
  public static function tokenKey(): string {
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
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'zusammenfassung') {
      return $form;
    }
    $langcode = $node->language()->getId();
    $latest = $this->reviewTransition->latest($node, $langcode);
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summary (editable)'),
      '#default_value' => (string) $latest->get('body')->value,
      '#rows' => 8,
    ];
    $form['langcode'] = ['#type' => 'hidden', '#value' => $langcode];
    $labels = [
      'freigeben' => $this->t('Approve'),
      'entwurf_speichern' => $this->t('Save draft'),
      'zurueckweisen' => $this->t('Reject'),
    ];
    $allowed = $this->reviewTransition->allowed($node, $this->currentUser(), $langcode);
    $form['actions'] = ['#type' => 'actions'];
    foreach ($labels as $transition => $label) {
      if (!in_array($transition, $allowed, TRUE)) {
        continue;
      }
      $url = Url::fromRoute('tannwald_ingest.review_transition', ['node' => $node->id(), 'transition' => $transition])->toString();
      $form['actions'][$transition] = [
        '#type' => 'submit',
        '#value' => $label,
        '#name' => $transition,
        '#attributes' => ['formaction' => $url, 'data-transition' => $transition],
      ];
      $form['#action'] ??= $url;
    }
    $form['#cache']['contexts'][] = 'user.permissions';
    $form['#cache']['tags'] = $latest->getCacheTags();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Die Buttons posten an die Route; hier kommt nur an, wer formaction nicht
    // unterstützt. Dann zurück zur Freigabe-Liste.
    $form_state->setRedirectUrl(Url::fromUserInput('/redaktion/freigaben'));
  }

}
