<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\EventSubscriber;

use Drupal\node\NodeInterface;
use Drupal\tannwald_access\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Audit "zugriff_verweigert" für 403 auf Node-Seite und Download.
 */
final class AccessDeniedSubscriber implements EventSubscriberInterface {

  /**
   * Überwachte Routen.
   */
  public const ROUTES = ['entity.node.canonical', 'tannwald_access.download'];

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
    if (!in_array($route, self::ROUTES, TRUE)) {
      return;
    }
    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }
    $this->audit->log('zugriff_verweigert', $node, ['route' => $route, 'nid' => (int) $node->id()]);
  }

}
