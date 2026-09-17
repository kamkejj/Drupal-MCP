<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prevents every response for the MCP resource from being cached.
 */
final class McpResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => 'onResponse',
    ];
  }

  /**
   * Applies the MCP cache policy, including route-level error responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $event->getRequest()->getPathInfo() !== '/mcp') {
      return;
    }

    $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
  }

}
