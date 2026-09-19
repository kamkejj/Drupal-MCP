<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Access;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_mcp\Authentication\BearerToken;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central MCP authorization gate.
 *
 * Every request to /mcp passes through authorize() before any MCP protocol
 * dispatch. The checks layered here, in order:
 *
 * 1. The request is authenticated by the Simple OAuth provider with a
 *    validated bearer token tied to an active Drupal user (enforced by the
 *    route's oauth2-only authentication and checked here).
 * 2. The token and account have at least one valid read or write capability
 *    pair (the configured scope and its corresponding Drupal permission).
 * 3. The access token carries a verifiable binding to this server's
 *    canonical /mcp URI (RFC 8707). Tokens whose claims cannot be inspected,
 *    or that name another resource, are rejected under the "require" policy.
 *
 * These are additional gates: entity/field access is re-checked inside every
 * tool, and OAuth itself remains owned by the contributed modules.
 */
final class McpAccessPolicy {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Authorizes a request. Returns NULL when allowed, or a response to send.
   */
  public function authorize(Request $request, AccountInterface $account): ?Response {
    if (!$account->isAuthenticated() || !$account instanceof TokenAuthUser) {
      return $this->unauthorized($request);
    }

    if (!$this->canAccessCapability($account, OperationCapability::Read)
      && !$this->canAccessCapability($account, OperationCapability::Write)) {
      $this->logger->info('MCP request denied: no valid MCP capability scope and permission pair.', [
        'uid' => $account->id(),
        'consumer' => $account->getConsumer()->getClientId(),
      ]);
      return $this->forbidden(
        'The access token and authenticated user do not grant an MCP operation capability.',
        sprintf(
          'Bearer error="insufficient_scope", scope="%s %s"',
          OperationCapability::Read->resolveScope($this->configFactory),
          OperationCapability::Write->resolveScope($this->configFactory),
        ),
      );
    }

    $resourceError = $this->checkResourceAudience($request, $account);
    if ($resourceError !== NULL) {
      $this->logger->info('MCP request denied: token issued for another resource.', [
        'uid' => $account->id(),
        'consumer' => $account->getConsumer()->getClientId(),
      ]);
      return $this->forbidden($resourceError);
    }

    return NULL;
  }

  /**
   * Extracts the scope names granted to the request's access token.
   *
   * @return list<string>
   *   The operation result.
   */
  public function grantedScopes(TokenAuthUser $account): array {
    $scopes = [];
    foreach ($account->getToken()->get('scopes')->getScopes() as $scope) {
      $scopes[] = $scope->getName();
    }
    return $scopes;
  }

  /**
   * Tests whether a token and account grant one operation capability.
   */
  public function canAccessCapability(AccountInterface $account, OperationCapability $capability): bool {
    if (!$account instanceof TokenAuthUser) {
      return FALSE;
    }
    $requiredScope = $capability->resolveScope($this->configFactory);
    return \in_array($requiredScope, $this->grantedScopes($account), TRUE)
      && $account->hasPermission($capability->permission());
  }

  /**
   * Checks that the access token was issued for this MCP resource.
   *
   * Simple OAuth access tokens are JWTs whose "aud" claim carries the OAuth
   * client ID by convention (League OAuth2 Server hardcodes it), which is NOT
   * a resource binding. This check therefore looks for an RFC 8707 "resource"
   * claim, or an "aud" entry naming this server's canonical /mcp URI:
   *
   * - A "resource" claim is present: it must name this resource, otherwise
   *   the token is rejected as issued for another resource.
   * - Otherwise, if "aud" contains the canonical /mcp URI, binding holds.
   * - Otherwise the token carries no verifiable resource information. This
   *   includes opaque tokens and tokens presented outside the Authorization
   *   header, whose claims cannot be inspected at all. Under the "require"
   *   policy (the shipped default) every such token is rejected — resource
   *   binding must be verifiable, never assumed. Under the interim "audit"
   *   policy they are accepted with an audit log entry, for deployments that
   *   cannot apply the bundled RFC 8707 patches; see
   *   docs/patches/simple-oauth-rfc8707.md.
   */
  private function checkResourceAudience(Request $request, TokenAuthUser $account): ?string {
    $claims = BearerToken::fromRequest($request)?->unsafeClaims();

    if (\is_array($claims)) {
      $expected = $this->canonicalResourceUri($request);
      $asStringList = static function (mixed $value): array {
        return \is_array($value) ? array_map('strval', $value) : (\is_string($value) ? [$value] : []);
      };

      $resourceClaims = $asStringList($claims['resource'] ?? NULL);
      if ($resourceClaims !== []) {
        return \in_array($expected, $resourceClaims, TRUE)
          ? NULL
          : 'The access token was issued for a different resource.';
      }

      $audClaims = $asStringList($claims['aud'] ?? NULL);
      if (\in_array($expected, $audClaims, TRUE)) {
        return NULL;
      }
    }

    // No verifiable resource information in the token.
    $policy = (string) ($this->configFactory->get('drupal_mcp.settings')->get('resource_binding') ?: 'audit');
    if ($policy === 'require') {
      return 'The access token does not include an audience binding for this resource.';
    }
    $this->logger->notice('MCP access token carries no resource audience binding (interim "audit" policy).');
    return NULL;
  }

  /**
   * The canonical absolute URI of this MCP resource.
   */
  public function canonicalResourceUri(Request $request): string {
    return (string) $this->configFactory->get('drupal_mcp.settings')->get('resource_uri');
  }

  /**
   * The 401 response with an RFC 9728-aware Bearer challenge.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The denied request.
   * @param string|null $error_description
   *   Optional detail from the contributed authentication provider, such as
   *   the reason a previously issued token no longer authenticates. Kept
   *   short and never includes token material.
   */
  public function unauthorized(Request $request, ?string $error_description = NULL): Response {
    $metadata = $request->getSchemeAndHttpHost() . '/.well-known/oauth-protected-resource';
    $challenge = sprintf('Bearer resource_metadata="%s"', $metadata);
    if ($error_description !== NULL) {
      // RFC 6750 error code for a presented token that failed validation.
      $challenge .= sprintf(', error="invalid_token", error_description="%s"', str_replace('"', "'", $error_description));
    }
    return new JsonResponse(
      ['error' => 'unauthorized', 'error_description' => 'A valid bearer access token is required.'],
      Response::HTTP_UNAUTHORIZED,
      [
        'WWW-Authenticate' => $challenge,
        'Cache-Control' => 'private, no-store',
      ],
    );
  }

  /**
   * The 403 response for insufficient scope or permission.
   */
  private function forbidden(string $message, ?string $challenge = NULL): Response {
    $headers = [
      'WWW-Authenticate' => $challenge ?? 'Bearer error="insufficient_scope", error_description="' . str_replace('"', "'", $message) . '"',
      'Cache-Control' => 'private, no-store',
    ];
    return new JsonResponse(
      ['error' => 'forbidden', 'error_description' => $message],
      Response::HTTP_FORBIDDEN,
      $headers,
    );
  }

}
