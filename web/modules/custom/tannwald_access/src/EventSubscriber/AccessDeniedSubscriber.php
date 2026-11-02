<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\EventSubscriber;

use Drupal\node\NodeInterface;
use Drupal\tannwald_access\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Audit "zugriff_verweigert" für 403 auf Node-Seite und Download.
 *
 * Weitere Routen mit einem Parameter "node" melden sich über die Routen-Option
 * "_tannwald_audit_denied: TRUE" an (z. B. tannwald_bulletin.confirm).
 */
final class AccessDeniedSubscriber implements EventSubscriberInterface {

  /**
   * Überwachte Routen.
   */
  public const ROUTES = ['entity.node.canonical', 'tannwald_access.download'];

  /**
   * Routen-Option für weitere überwachte Routen.
   */
  public const ROUTE_OPTION = '_tannwald_audit_denied';

  public function __construct(
    private readonly AuditLogger $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Vor den Core-Subscribern, die die 403-Seite rendern (-50 … -256).
    return [KernelEvents::EXCEPTION => ['onException', 60]];
  }

  /**
   * Schreibt eine Audit-Zeile, setzt keine Antwort.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest() || !$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }
    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');
    $routeObject = $request->attributes->get('_route_object');
    $optedIn = $routeObject instanceof Route && $routeObject->getOption(self::ROUTE_OPTION);
    if (!in_array($route, self::ROUTES, TRUE) && !$optedIn) {
      return;
    }
    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }
    $this->audit->log('zugriff_verweigert', $node, ['route' => $route, 'nid' => (int) $node->id()]);
  }

}
