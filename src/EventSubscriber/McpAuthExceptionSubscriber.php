<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\EventSubscriber;

use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts authentication failures on /mcp into JSON challenges.
 *
 * Two failure shapes must never surface as Drupal HTML: the contributed
 * OAuth provider throws its 401 exception while Drupal authenticates the
 * request, which happens BEFORE routing (so the route name is unavailable
 * and the endpoint path is matched instead), and core's authentication
 * filter rejects cookie-only requests after routing with an access-denied
 * exception. Both are answered with the RFC 9728-aware Bearer challenge MCP
 * clients understand, with no-store caching semantics.
 */
final class McpAuthExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly McpAccessPolicy $accessPolicy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Needs to run before core's ExceptionHtmlSubscriber renders a page.
    return [
      KernelEvents::EXCEPTION => ['onException', 50],
    ];
  }

  /**
   * Handles kernel exceptions for the MCP endpoint.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->getPathInfo() !== '/mcp') {
      return;
    }

    $throwable = $event->getThrowable();
    $description = NULL;
    if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() === 401) {
      // A presented bearer token failed contributed validation (revoked,
      // blocked user, malformed). Keep the provider's reason without its
      // headers, which would drop the MCP resource metadata pointer.
      $description = $throwable->getMessage();
    }
    elseif (!$throwable instanceof AccessDeniedHttpException) {
      return;
    }

    $event->setResponse($this->accessPolicy->unauthorized($request, $description));
  }

}
