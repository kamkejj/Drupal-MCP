<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Drupal\drupal_mcp\Http\McpRequestValidator;
use Drupal\drupal_mcp\Mcp\ServerFactory;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /mcp endpoint: Drupal request → OAuth gate → MCP SDK dispatch.
 *
 * The adapter stays thin on purpose. Authentication is delegated to the
 * Simple OAuth provider (the route only trusts "oauth2"), authorization to
 * McpAccessPolicy, protocol handling to the MCP SDK, and domain logic to the
 * registered tools.
 */
final class McpController extends ControllerBase {

  public function __construct(
    private readonly AccountProxyInterface $accountProxy,
    private readonly McpAccessPolicy $accessPolicy,
    private readonly ServerFactory $serverFactory,
    private readonly McpRequestValidator $requestValidator,
    private readonly HttpMessageFactoryInterface $httpMessageFactory,
    private readonly HttpFoundationFactoryInterface $httpFoundationFactory,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('drupal_mcp.access_policy'),
      $container->get('drupal_mcp.server_factory'),
      $container->get('drupal_mcp.request_validator'),
      $container->get('psr7.http_message_factory'),
      $container->get('psr7.http_foundation_factory'),
      $container->get('flood'),
      $container->get('logger.channel.drupal_mcp'),
    );
  }

  /**
   * Handles an MCP endpoint request.
   */
  public function handle(Request $request): Response {
    $started = microtime(TRUE);

    $requestDenial = $this->requestValidator->validate($request);
    if ($requestDenial !== NULL) {
      return $this->privateResponse(new JsonResponse(
        ['error' => 'forbidden', 'error_description' => $requestDenial],
        Response::HTTP_FORBIDDEN,
      ));
    }

    $account = $this->accountProxy->getAccount();
    $denial = $this->accessPolicy->authorize($request, $account);
    if ($denial !== NULL) {
      return $this->privateResponse($denial);
    }
    \assert($account instanceof TokenAuthUser);

    $settings = $this->config('drupal_mcp.settings');
    $consumerId = (string) $account->getConsumer()->uuid();
    $floodKey = 'drupal_mcp.request';
    $identifier = $account->id() . ':' . $consumerId;
    $limit = max(1, (int) $settings->get('flood_limit'));
    $window = max(60, (int) $settings->get('flood_window'));
    if (!$this->flood->isAllowed($floodKey, $limit, $window, $identifier)) {
      return $this->privateResponse(new JsonResponse(
        ['error' => 'too_many_requests', 'error_description' => 'MCP request rate limit exceeded.'],
        Response::HTTP_TOO_MANY_REQUESTS,
      ));
    }
    $this->flood->register($floodKey, $window, $identifier);

    try {
      $response = $this->dispatch($request);
      $this->logger->info('MCP request dispatched.', [
        'uid' => $account->id(),
        'consumer' => $account->getConsumer()->getClientId(),
        'method' => $request->getMethod(),
        'status' => $response->getStatusCode(),
        'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
      ]);
      return $this->privateResponse($response);
    }
    catch (\Throwable $e) {
      // Protocol failures are converted by the SDK; anything reaching here is
      // an integration fault. Sanitize: no exception traces or paths leak.
      $this->logger->error('MCP dispatch failed with @type.', [
        'uid' => $account->id(),
        '@type' => $e::class,
      ]);
      return $this->privateResponse(new JsonResponse(
        ['error' => 'internal_error', 'error_description' => 'The MCP request could not be processed.'],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      ));
    }
  }

  /**
   * Bridges the request through the MCP SDK and back to a Drupal response.
   */
  private function dispatch(Request $request): Response {
    $settings = $this->config('drupal_mcp.settings');
    $maxBody = max(1024, (int) $settings->get('max_body_bytes'));

    $allowedHosts = array_values(array_filter($settings->get('allowed_hosts') ?? []));
    $allowedOrigins = array_values(array_filter($settings->get('allowed_origins') ?? []));

    $psr7Request = $this->httpMessageFactory->createRequest($request);
    $server = $this->serverFactory->forAccount($this->accountProxy->getAccount());

    $transport = new StreamableHttpTransport(
      $psr7Request,
      logger: $this->logger,
      middleware: [
        new CorsMiddleware($allowedOrigins),
        new DnsRebindingProtectionMiddleware($allowedHosts),
      ],
      maxBodyBytes: $maxBody,
    );

    $psr7Response = $server->run($transport);
    \assert($psr7Response instanceof ResponseInterface);

    // The SDK uses a lazy callback stream for SSE responses. Converting that
    // stream to a buffered Symfony response consumes it outside the response
    // lifecycle, yielding an empty or HTML response after a tool has run.
    $streamed = str_starts_with(strtolower($psr7Response->getHeaderLine('Content-Type')), 'text/event-stream');
    return $this->httpFoundationFactory->createResponse($psr7Response, $streamed);
  }

  /**
   * Prevents every MCP response from being stored by shared or private caches.
   */
  private function privateResponse(Response $response): Response {
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
