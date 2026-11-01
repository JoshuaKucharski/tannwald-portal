<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\tannwald_ingest\Form\ReviewForm;
use Drupal\tannwald_ingest\Review\ReviewTransition;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * POST /redaktion/zusammenfassung/{node}/{transition} (Formular und JSON).
 */
final class ReviewController extends ControllerBase {

  public function __construct(
    protected ReviewTransition $reviewTransition,
    protected CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('tannwald_ingest.review_transition'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Führt die Transition aus.
   *
   * Formular: Feld form_token (ReviewForm), body, langcode; Antwort Redirect
   * (destination oder /redaktion/freigaben). JSON (Content-Type oder Accept):
   * Header X-CSRF-Token von /session/token, {body?, langcode?}; Antwort
   * {nid, vid, moderation_state, bearbeitet}, Fehler {fehler} mit 400/403.
   */
  public function transition(Request $request, NodeInterface $node, string $transition): Response {
    $json = self::wantsJson($request);
    try {
      $data = $json ? (json_decode((string) $request->getContent(), TRUE) ?: []) : $request->request->all();
      if (!$this->validToken($request, $json)) {
        throw new AccessDeniedHttpException('Missing or invalid CSRF token.');
      }
      $body = isset($data['body']) && is_string($data['body']) ? $data['body'] : NULL;
      $langcode = isset($data['langcode']) && is_string($data['langcode']) && $data['langcode'] !== '' ? $data['langcode'] : NULL;
      $result = $this->reviewTransition->apply($node, $transition, $body, $this->currentUser(), $langcode);
    }
    catch (HttpExceptionInterface $e) {
      if ($json) {
        return new JsonResponse(['fehler' => $e->getMessage()], $e->getStatusCode());
      }
      throw $e;
    }
    if ($json) {
      return new JsonResponse($result);
    }
    $messages = [
      'freigeben' => $this->t('The summary has been approved.'),
      'zurueckweisen' => $this->t('The summary has been rejected.'),
      'entwurf_speichern' => $this->t('The summary has been saved as draft.'),
    ];
    $this->messenger()->addStatus($messages[$transition]);
    // Ein ?destination= überschreibt das Ziel (RedirectResponseSubscriber).
    return new RedirectResponse(Url::fromUserInput('/redaktion/freigaben')->setAbsolute()->toString());
  }

  /**
   * Header-Token (wie /session/token) oder Form-Token des ReviewForm.
   */
  protected function validToken(Request $request, bool $json): bool {
    $header = (string) $request->headers->get('X-CSRF-Token', '');
    if ($header !== '' && $this->csrfToken->validate($header, CsrfRequestHeaderAccessCheck::TOKEN_KEY)) {
      return TRUE;
    }
    if ($json) {
      return FALSE;
    }
    $formToken = (string) $request->request->get('form_token', '');
    return $formToken !== '' && $this->csrfToken->validate($formToken, ReviewForm::FORM_ID);
  }

  /**
   * JSON-Anfrage (Content-Type) oder JSON-Antwort gewünscht (Accept)?
   */
  protected static function wantsJson(Request $request): bool {
    return $request->getContentTypeFormat() === 'json'
      || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
  }

}
