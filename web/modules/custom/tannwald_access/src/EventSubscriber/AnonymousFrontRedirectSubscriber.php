<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Startseite: anonym zur Anmeldung, angemeldet zu „Meine Maschinen“.
 *
 * Anonyme Besucher haben kein „access content“ (2.1); statt 403 auf „/“ gibt es
 * 302 nach /user/login?destination=/meine-maschinen (Anpassung 16, Theme 5.1).
 * Sprachpräfixe (/en, /fr) bleiben erhalten. Läuft vor dem Router, damit die
 * 403-Ausnahme gar nicht erst entsteht.
 */
final class AnonymousFrontRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Ziel nach der Anmeldung und für angemeldete Besucher von „/“.
   */
  public const TARGET = '/meine-maschinen';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Nach der Authentifizierung (300), vor dem Router (32).
    return [KernelEvents::REQUEST => ['onRequest', 40]];
  }

  /**
   * Setzt die Weiterleitung für „/“ (mit oder ohne Sprachpräfix).
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)) {
      return;
    }
    $prefix = $this->languagePrefix(rtrim($request->getPathInfo(), '/'));
    if ($prefix === NULL) {
      return;
    }
    $base = $request->getBasePath();
    if ($this->currentUser->isAnonymous()) {
      $destination = rawurlencode($prefix . self::TARGET);
      $url = $base . $prefix . '/user/login?destination=' . $destination;
    }
    else {
      $url = $base . $prefix . self::TARGET;
    }
    $response = new RedirectResponse($url, 302);
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-cache, private');
    $event->setResponse($response);
  }

  /**
   * Sprachpräfix des Pfads („“ für „/“, „/en“ für „/en“); NULL sonst.
   */
  private function languagePrefix(string $path): ?string {
    if ($path === '') {
      return '';
    }
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes') ?? [];
    $candidate = ltrim($path, '/');
    if (!in_array($candidate, array_filter((array) $prefixes), TRUE)) {
      return NULL;
    }
    return '/' . $candidate;
  }

}
