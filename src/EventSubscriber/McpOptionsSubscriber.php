<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\EventSubscriber;

use Drupal\drupal_mcp\Http\McpRequestValidator;
use Mcp\Schema\Wire\McpHeader;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers MCP CORS preflights before Drupal's generic OPTIONS subscriber.
 */
final class McpOptionsSubscriber implements EventSubscriberInterface {

  private const ALLOWED_METHODS = 'POST, GET, DELETE, OPTIONS';

  private const PREFLIGHT_HEADERS = [
    'Accept',
    'Authorization',
    'Content-Type',
    'Last-Event-ID',
    McpHeader::PROTOCOL_VERSION,
    StreamableHttpTransport::SESSION_HEADER,
    McpHeader::METHOD,
    McpHeader::NAME,
  ];

  public function __construct(
    private readonly McpRequestValidator $requestValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 1001],
    ];
  }

  /**
   * Handles an MCP OPTIONS request with endpoint-specific CORS policy.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (!$request->isMethod(Request::METHOD_OPTIONS) || $request->getPathInfo() !== '/mcp') {
      return;
    }

    $denial = $this->requestValidator->validate($request);
    if ($denial !== NULL) {
      $event->setResponse(new Response($denial, Response::HTTP_FORBIDDEN, [
        'Cache-Control' => 'private, no-store',
      ]));
      return;
    }

    $origin = $request->headers->get('Origin');
    if ($origin === NULL) {
      $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, [
        'Allow' => self::ALLOWED_METHODS,
        'Cache-Control' => 'private, no-store',
      ]));
      return;
    }

    $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, [
      'Access-Control-Allow-Origin' => $origin,
      'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
      'Access-Control-Allow-Headers' => implode(', ', self::PREFLIGHT_HEADERS),
      'Access-Control-Expose-Headers' => StreamableHttpTransport::SESSION_HEADER,
      'Vary' => 'Origin',
      'Allow' => self::ALLOWED_METHODS,
      'Cache-Control' => 'private, no-store',
    ]));
  }

}
