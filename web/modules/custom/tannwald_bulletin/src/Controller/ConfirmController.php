<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\tannwald_bulletin\ConfirmationService;
use Drupal\tannwald_bulletin\Form\ConfirmForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /bulletin/{node}/bestaetigen.
 */
final class ConfirmController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly ConfirmationService $confirmations,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly MessengerInterface $messenger,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Zugriff: Bundle bulletin und ein gültiges Token.
   *
   * JSON-Weg: Header X-CSRF-Token (Wert von /session/token); Formular-Weg:
   * form_token des Formulars tannwald_bulletin_confirm_form. Core bildet das
   * Formular-Token über den Platzhalter "form_token_placeholder_<hash>"
   * (FormBuilder::prepareForm), nicht über die Formular-ID selbst.
   */
  public function access(NodeInterface $node, Request $request): AccessResultInterface {
    if ($node->bundle() !== 'bulletin') {
      return AccessResult::forbidden('Not a bulletin.')->setCacheMaxAge(0);
    }
    $header = $request->headers->get('X-CSRF-Token');
    $valid = $header !== NULL
      ? $this->csrfToken->validate($header, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      : $this->csrfToken->validate((string) $request->request->get('form_token', ''), ConfirmForm::tokenSeed());
    return AccessResult::allowedIf($valid)->setCacheMaxAge(0);
  }

  /**
   * Speichert die Bestätigung; Redirect oder JSON.
   */
  public function confirm(NodeInterface $node, Request $request): Response {
    $machineId = $this->machineId($request);
    $machine = $machineId > 0 ? $this->entityTypeManager->getStorage('node')->load($machineId) : NULL;
    if (!$machine instanceof NodeInterface) {
      // Keine Auskunft, ob die Maschine existiert.
      throw new AccessDeniedHttpException('Unknown machine.');
    }
    $already = $this->confirmations->isConfirmed((int) $node->id(), $machineId, (int) $this->currentUser->id()) !== NULL;
    $confirmation = $this->confirmations->confirm($node, $machine, $this->currentUser);

    if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
      return new JsonResponse([
        'bestaetigt_am' => date(DATE_ATOM, $confirmation->getConfirmedTime()),
        'maschine_nid' => $machineId,
        'bereits_bestaetigt' => $already,
      ]);
    }
    $this->messenger->addStatus($this->t('Read confirmation saved.'));
    // Eine ?destination= der Anfrage ersetzt dieses Ziel (Core-Subscriber).
    return new LocalRedirectResponse($machine->toUrl()->toString());
  }

  /**
   * Maschinen-NID aus JSON {maschine} oder Formularfeld maschine.
   */
  private function machineId(Request $request): int {
    if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
      $payload = json_decode((string) $request->getContent(), TRUE);
      $value = is_array($payload) ? ($payload['maschine'] ?? NULL) : NULL;
    }
    else {
      $value = $request->request->get('maschine');
    }
    if ($value === NULL || $value === '' || !ctype_digit((string) $value)) {
      throw new BadRequestHttpException('Parameter "maschine" missing.');
    }
    return (int) $value;
  }

}
