<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\Tests\simple_oauth\Functional\TokenBearerFunctionalTestBase;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Query;
use Mcp\Schema\Wire\McpHeader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the MCP endpoint through Drupal's HTTP request lifecycle.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class McpEndpointFunctionalTest extends TokenBearerFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_mcp'];

  private const ALLOWED_ORIGIN = 'https://allowed.example';

  private const MODERN_VERSION = '2026-07-28';

  /**
   * Role assigned to the ordinary MCP reader.
   */
  private string $readerRoleId = '';

  /**
   * User used for ordinary MCP access.
   */
  private UserInterface $readerUser;

  /**
   * User with an additional native administrative permission.
   */
  private UserInterface $administratorUser;

  /**
   * User and role authorized for MCP write operations.
   */
  private UserInterface $writerUser;

  /**
   * Role assigned to the MCP writer.
   */
  private string $writerRoleId = '';

  /**
   * PKCE verifier associated with the latest authorization grant.
   */
  private string $codeVerifier = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Role::create([
      'id' => 'mcp_read_ceiling',
      'label' => 'MCP read scope ceiling',
      'permissions' => [
        'access mcp read',
        'administer permissions',
      ],
    ])->save();
    $administratorRoleId = $this->randomMachineName();
    Role::create([
      'id' => $administratorRoleId,
      'label' => 'MCP administrator',
      'permissions' => [
        'access mcp read',
        'administer permissions',
        'grant simple_oauth codes',
      ],
    ])->save();
    $this->readerRoleId = $this->randomMachineName();
    Role::create([
      'id' => $this->readerRoleId,
      'label' => 'MCP reader',
      'permissions' => [
        'access mcp read',
        'grant simple_oauth codes',
      ],
    ])->save();
    $this->writerRoleId = $this->randomMachineName();
    Role::create([
      'id' => $this->writerRoleId,
      'label' => 'MCP writer',
      'permissions' => [
        'access mcp write',
        'administer taxonomy',
        'grant simple_oauth codes',
      ],
    ])->save();

    Oauth2Scope::create([
      'id' => 'mcp_read',
      'name' => 'mcp:read',
      'description' => 'MCP read access',
      'granularity_id' => Oauth2ScopeInterface::GRANULARITY_ROLE,
      'granularity_configuration' => ['role' => 'mcp_read_ceiling'],
      'grant_types' => [
        'authorization_code' => ['status' => TRUE, 'description' => ''],
        'refresh_token' => ['status' => TRUE, 'description' => ''],
      ],
    ])->save();
    Oauth2Scope::create([
      'id' => 'mcp_write',
      'name' => 'mcp:write',
      'description' => 'MCP write access',
      'granularity_id' => Oauth2ScopeInterface::GRANULARITY_ROLE,
      'granularity_configuration' => ['role' => $this->writerRoleId],
      'grant_types' => [
        'authorization_code' => ['status' => TRUE, 'description' => ''],
        'refresh_token' => ['status' => TRUE, 'description' => ''],
      ],
    ])->save();
    $this->client
      ->set('confidential', FALSE)
      ->set('pkce', TRUE)
      ->set('third_party', TRUE)
      ->set('automatic_authorization', FALSE)
      ->set('grant_types', ['authorization_code', 'refresh_token'])
      ->set('scopes', [['scope_id' => 'mcp_read'], ['scope_id' => 'mcp_write']])
      ->save();

    $readerPassword = $this->randomString();
    $this->readerUser = User::create([
      'name' => 'mcp_reader',
      'mail' => 'mcp_reader@example.com',
      'pass' => $readerPassword,
      'status' => 1,
      'roles' => [$this->readerRoleId],
    ]);
    $this->readerUser->save();
    $this->administratorUser = User::create([
      'name' => 'mcp_administrator',
      'mail' => 'mcp_administrator@example.com',
      'pass' => $this->randomString(),
      'status' => 1,
      'roles' => [$administratorRoleId],
    ]);
    $this->administratorUser->save();
    $this->writerUser = User::create([
      'name' => 'mcp_writer',
      'mail' => 'mcp_writer@example.com',
      'pass' => $this->randomString(),
      'status' => 1,
      'roles' => [$this->writerRoleId],
    ]);
    $this->writerUser->save();

    $this->config('drupal_mcp.settings')
      ->set('resource_uri', $this->baseUrl . '/mcp')
      ->set('resource_binding', 'require')
      ->set('read_scope', 'mcp:read')
      ->set('allowed_origins', [self::ALLOWED_ORIGIN])
      ->set('allowed_hosts', [
        parse_url($this->baseUrl, PHP_URL_HOST),
        parse_url(self::ALLOWED_ORIGIN, PHP_URL_HOST),
      ])
      ->save();
  }

  /**
   * Tests that a modern-revision browser preflight may send method/name.
   */
  public function testModernPreflightAdvertisesRequestRoutingHeaders(): void {
    $response = $this->requestMcp('OPTIONS', NULL, [
      'Origin' => self::ALLOWED_ORIGIN,
      'Access-Control-Request-Method' => 'POST',
      'Access-Control-Request-Headers' => 'authorization, content-type, mcp-protocol-version, mcp-method, mcp-name',
    ]);

    $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(self::ALLOWED_ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    $this->assertStringContainsStringIgnoringCase('mcp-method', $response->getHeaderLine('Access-Control-Allow-Headers'));
    $this->assertStringContainsStringIgnoringCase('mcp-name', $response->getHeaderLine('Access-Control-Allow-Headers'));

    $denied = $this->requestMcp('OPTIONS', NULL, [
      'Origin' => 'https://evil.example',
      'Access-Control-Request-Method' => 'POST',
    ]);
    $this->assertSame(403, $denied->getStatusCode());
    $this->assertSame('', $denied->getHeaderLine('Access-Control-Allow-Origin'));
  }

  /**
   * Tests bearer challenges, a real PKCE grant, refresh, and MCP dispatch.
   */
  public function testRealOauthGrantAndMcpDispatch(): void {
    $metadataResponse = $this->getHttpClient()->request('GET', $this->baseUrl . '/.well-known/oauth-protected-resource', [
      'http_errors' => FALSE,
    ]);
    $this->assertSame(200, $metadataResponse->getStatusCode());
    $metadata = $this->decodeJson($metadataResponse);
    $this->assertSame(['mcp:read', 'mcp:write'], $metadata['scopes_supported']);

    $anonymous = $this->requestMcp('POST', NULL, $this->modernHeaders(), $this->modernBody(1, 'tools/list'));
    $this->assertSame(401, $anonymous->getStatusCode());
    $this->assertSame('application/json', $anonymous->getHeaderLine('Content-Type'));
    $this->assertStringContainsString(
      'Bearer resource_metadata=',
      $anonymous->getHeaderLine('WWW-Authenticate'),
    );
    $this->assertSame('unauthorized', $this->decodeJson($anonymous)['error']);
    $this->assertPrivateNoStore($anonymous);

    $invalid = $this->requestMcp('POST', 'not-a-real-token', $this->modernHeaders(), $this->modernBody(2, 'tools/list'));
    $this->assertSame(401, $invalid->getStatusCode());

    $this->drupalLogin($this->readerUser);
    $cookieOnly = $this->requestMcp('POST', NULL, $this->modernHeaders(), $this->modernBody(3, 'tools/list'));
    $this->assertSame(401, $cookieOnly->getStatusCode());

    $tokens = $this->authorizeToken();
    $claims = $this->jwtClaims($tokens['access_token']);
    $this->assertSame([$this->baseUrl . '/mcp'], (array) $claims['resource']);

    $tools = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(4, 'tools/list'));
    $this->assertSame(200, $tools->getStatusCode(), (string) $tools->getBody());
    $this->assertStringContainsString('private', $tools->getHeaderLine('Cache-Control'));
    $this->assertStringContainsString('no-store', $tools->getHeaderLine('Cache-Control'));
    $toolNames = array_column($this->decodeJson($tools)['result']['tools'], 'name');
    $this->assertContains('drupal_site_info', $toolNames);
    $this->assertContains('drupal_whoami', $toolNames);

    $allowedOrigin = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list') + [
      'Origin' => self::ALLOWED_ORIGIN,
    ], $this->modernBody(5, 'tools/list'));
    $this->assertSame(200, $allowedOrigin->getStatusCode());
    $this->assertSame(self::ALLOWED_ORIGIN, $allowedOrigin->getHeaderLine('Access-Control-Allow-Origin'));

    $invalidVersion = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list', '1999-01-01'), $this->modernBody(6, 'tools/list', NULL, '1999-01-01'));
    $this->assertSame(400, $invalidVersion->getStatusCode());
    $invalidVersionBody = $this->decodeJson($invalidVersion);
    $this->assertSame(-32022, $invalidVersionBody['error']['code']);
    $this->assertSame(6, $invalidVersionBody['id']);
    $this->assertPrivateNoStore($invalidVersion);

    $versionMismatch = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list', '1999-01-01'), $this->modernBody(7, 'tools/list'));
    $this->assertSame(-32020, $this->decodeJson($versionMismatch)['error']['code']);

    $missingMethodHeaders = $this->modernHeaders('tools/list');
    unset($missingMethodHeaders[McpHeader::METHOD]);
    $missingMethod = $this->requestMcp('POST', $tokens['access_token'], $missingMethodHeaders, $this->modernBody(8, 'tools/list'));
    $this->assertSame(-32020, $this->decodeJson($missingMethod)['error']['code']);

    $methodMismatch = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('server/discover'), $this->modernBody(9, 'tools/list'));
    $this->assertSame(-32020, $this->decodeJson($methodMismatch)['error']['code']);

    $missingName = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/call'), $this->modernBody(10, 'tools/call', 'drupal_site_info'));
    $this->assertSame(-32020, $this->decodeJson($missingName)['error']['code']);

    $modernGet = $this->requestMcp('GET', $tokens['access_token'], $this->modernHeaders());
    $this->assertSame(400, $modernGet->getStatusCode());
    $this->assertSame(-32022, $this->decodeJson($modernGet)['error']['code']);

    $modernDelete = $this->requestMcp('DELETE', $tokens['access_token'], $this->modernHeaders());
    $this->assertSame(400, $modernDelete->getStatusCode());
    $this->assertSame(-32022, $this->decodeJson($modernDelete)['error']['code']);

    $unsupportedMethod = $this->requestMcp('PATCH', $tokens['access_token'], $this->modernHeaders());
    $this->assertSame(405, $unsupportedMethod->getStatusCode());
    $this->assertPrivateNoStore($unsupportedMethod);

    $wrongOrigin = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list') + [
      'Origin' => 'http://allowed.example',
    ], $this->modernBody(11, 'tools/list'));
    $this->assertSame(403, $wrongOrigin->getStatusCode());

    $refreshed = $this->post($this->url, [
      'grant_type' => 'refresh_token',
      'refresh_token' => $tokens['refresh_token'],
      'client_id' => $this->client->getClientId(),
      'code_verifier' => $this->codeVerifier,
    ]);
    $this->assertSame(200, $refreshed->getStatusCode(), (string) $refreshed->getBody());
    $refreshedTokens = Json::decode((string) $refreshed->getBody());
    $this->assertNotEmpty($refreshedTokens['access_token']);
    $this->assertSame([$this->baseUrl . '/mcp'], (array) $this->jwtClaims($refreshedTokens['access_token'])['resource']);
    $refreshedTools = $this->requestMcp('POST', $refreshedTokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(12, 'tools/list'));
    $this->assertSame(200, $refreshedTools->getStatusCode());
  }

  /**
   * Tests tool, permission, and token state changes on an existing grant.
   */
  public function testMcpStateChangesFailClosed(): void {
    $tokens = $this->authorizeToken();

    $this->config('drupal_mcp.settings')
      ->set('families.site', FALSE)
      ->save();
    $tools = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(7, 'tools/list'));
    $toolNames = array_column($this->decodeJson($tools)['result']['tools'], 'name');
    $this->assertNotContains('drupal_site_info', $toolNames);
    $this->assertContains('drupal_entity_types_list', $toolNames);

    $hiddenCall = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/call', self::MODERN_VERSION, 'drupal_site_info'), $this->modernBody(8, 'tools/call', 'drupal_site_info'));
    $this->assertSame('Tool not found: "drupal_site_info".', $this->decodeJson($hiddenCall)['error']['message']);

    $this->config('drupal_mcp.settings')
      ->set('families.site', TRUE)
      ->save();
    Role::load($this->readerRoleId)?->revokePermission('access mcp read')->save();
    $denied = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(9, 'tools/list'));
    $this->assertSame(403, $denied->getStatusCode());
    $this->assertSame('forbidden', $this->decodeJson($denied)['error']);
    $this->assertPrivateNoStore($denied);

    Role::load($this->readerRoleId)?->grantPermission('access mcp read')->save();
    $this->container->get('simple_oauth.repositories.access_token')
      ->revokeAccessToken($this->jwtClaims($tokens['access_token'])['jti']);
    $revoked = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(10, 'tools/list'));
    $this->assertSame(401, $revoked->getStatusCode());
    $this->assertPrivateNoStore($revoked);
  }

  /**
   * Tests real read/write grants keep mutation catalogues capability-isolated.
   */
  public function testWriteScopeCatalogueIsolation(): void {
    $this->config('drupal_mcp.settings')
      ->set('mutation_families.taxonomy', TRUE)
      ->set('writable_vocabularies', ['tags'])
      ->set('mutation_families.node', TRUE)
      ->set('writable_node_bundles', ['article'])
      ->save();

    $readerTokens = $this->authorizeToken();
    $this->drupalLogout();
    $writerTokens = $this->authorizeToken($this->writerUser, TRUE, NULL, 'mcp:write');

    $this->assertNotContains('drupal_term_create', $this->toolNames($readerTokens['access_token'], 30));
    $this->assertNotContains('drupal_content_create', $this->toolNames($readerTokens['access_token'], 30));
    $writerTools = $this->toolNames($writerTokens['access_token'], 31);
    $this->assertContains('drupal_term_create', $writerTools);
    $this->assertContains('drupal_term_update', $writerTools);
    $this->assertContains('drupal_content_create', $writerTools);
    $this->assertContains('drupal_content_update', $writerTools);
    $this->assertNotContains('drupal_site_info', $writerTools);

    Role::load($this->writerRoleId)?->revokePermission('access mcp write')->save();
    $denied = $this->requestMcp('POST', $writerTokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(32, 'tools/list'));
    $this->assertSame(403, $denied->getStatusCode());
  }

  /**
   * Tests per-user/client request flood control.
   */
  public function testRequestLimit(): void {
    $tokens = $this->authorizeToken();
    $this->config('drupal_mcp.settings')
      ->set('flood_limit', 2)
      ->set('flood_window', 3600)
      ->save();

    for ($requestId = 11; $requestId <= 12; $requestId++) {
      $response = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody($requestId, 'tools/list'));
      $this->assertSame(200, $response->getStatusCode());
    }

    $limited = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(13, 'tools/list'));
    $this->assertSame(429, $limited->getStatusCode());
    $this->assertSame('too_many_requests', $this->decodeJson($limited)['error']);
    $this->assertPrivateNoStore($limited);
  }

  /**
   * Tests the configured request-body limit before protocol dispatch.
   */
  public function testRequestBodyLimit(): void {
    $tokens = $this->authorizeToken();
    $this->config('drupal_mcp.settings')
      ->set('max_body_bytes', 1024)
      ->save();

    $oversized = $this->modernBody(14, 'tools/list') . str_repeat(' ', 1024);
    $response = $this->requestMcp('POST', $tokens['access_token'], $this->modernHeaders('tools/list'), $oversized);
    $this->assertSame(413, $response->getStatusCode());
    $this->assertSame(-32600, $this->decodeJson($response)['error']['code']);
    $this->assertPrivateNoStore($response);
  }

  /**
   * Tests tool catalogues remain isolated across users sharing one scope.
   */
  public function testToolCatalogueVariesByUserInBothRequestOrders(): void {
    $readerTokens = $this->authorizeToken();
    $this->drupalLogout();
    $administratorTokens = $this->authorizeToken($this->administratorUser);

    $administratorTools = $this->toolNames($administratorTokens['access_token'], 15);
    $this->assertContains('drupal_roles_list', $administratorTools);

    $readerTools = $this->toolNames($readerTokens['access_token'], 16);
    $this->assertNotContains('drupal_roles_list', $readerTools);

    $administratorToolsAgain = $this->toolNames($administratorTokens['access_token'], 17);
    $this->assertContains('drupal_roles_list', $administratorToolsAgain);
  }

  /**
   * Tests PKCE, resource binding, and public-client token revocation failures.
   */
  public function testOauthSecurityFailuresAndRevocation(): void {
    $grant = $this->authorizeCode();
    $badVerifier = $this->exchangeCode($grant['code'], 'not-the-verifier');
    $this->assertSame(400, $badVerifier->getStatusCode());

    $unbound = $this->authorizeToken(NULL, FALSE);
    $unboundResponse = $this->requestMcp('POST', $unbound['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(18, 'tools/list'));
    $this->assertSame(403, $unboundResponse->getStatusCode());
    $this->assertPrivateNoStore($unboundResponse);

    $wrongResource = $this->authorizeToken(NULL, TRUE, $this->baseUrl . '/not-mcp');
    $wrongResourceResponse = $this->requestMcp('POST', $wrongResource['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(19, 'tools/list'));
    $this->assertSame(403, $wrongResourceResponse->getStatusCode());

    $revokedTokens = $this->authorizeToken();
    $unaffectedTokens = $this->authorizeToken();
    $revocation = $this->post(Url::fromRoute('simple_oauth_server_metadata.revoke'), [
      'token' => $revokedTokens['access_token'],
      'token_type_hint' => 'access_token',
      'client_id' => $this->client->getClientId(),
    ]);
    $this->assertSame(200, $revocation->getStatusCode(), (string) $revocation->getBody());

    $revoked = $this->requestMcp('POST', $revokedTokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(20, 'tools/list'));
    $this->assertSame(401, $revoked->getStatusCode());
    $unaffected = $this->requestMcp('POST', $unaffectedTokens['access_token'], $this->modernHeaders('tools/list'), $this->modernBody(21, 'tools/list'));
    $this->assertSame(200, $unaffected->getStatusCode());
  }

  /**
   * Sends a request to the real MCP route.
   */
  private function requestMcp(string $method, ?string $token, array $headers = [], ?string $body = NULL): Response {
    $this->getSession()->setCookie(
      'SIMPLETEST_USER_AGENT',
      drupal_generate_test_ua($this->databasePrefix),
    );

    if ($token !== NULL) {
      $headers['Authorization'] = "Bearer {$token}";
    }
    $options = [
      'headers' => $headers,
      'http_errors' => FALSE,
    ];
    if ($body !== NULL) {
      $options['body'] = $body;
    }

    $response = $this->getHttpClient()->request($method, $this->baseUrl . '/mcp', $options);
    \assert($response instanceof Response);
    return $response;
  }

  /**
   * Completes a real authorization-code + PKCE flow for the reader.
   */
  private function authorizeToken(?UserInterface $user = NULL, bool $includeResource = TRUE, ?string $resource = NULL, string $scope = 'mcp:read'): array {
    $grant = $this->authorizeCode($user, $includeResource, $resource, $scope);
    $response = $this->exchangeCode($grant['code'], $grant['verifier']);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $tokens = Json::decode((string) $response->getBody());
    $this->assertNotEmpty($tokens['access_token']);
    $this->assertNotEmpty($tokens['refresh_token']);
    return $tokens;
  }

  /**
   * Creates one authorization code and its PKCE verifier.
   */
  private function authorizeCode(?UserInterface $user = NULL, bool $includeResource = TRUE, ?string $resource = NULL, string $scope = 'mcp:read'): array {
    $this->drupalLogin($user ?? $this->readerUser);
    $this->codeVerifier = bin2hex(random_bytes(32));
    $challenge = self::base64urlencode(hash('sha256', $this->codeVerifier, TRUE));

    $query = [
      'response_type' => 'code',
      'client_id' => $this->client->getClientId(),
      'redirect_uri' => $this->redirectUri,
      'scope' => $scope,
      'state' => 'functional-test',
      'code_challenge' => $challenge,
      'code_challenge_method' => 'S256',
    ];
    if ($includeResource) {
      $query['resource'] = $resource ?? $this->baseUrl . '/mcp';
    }
    $this->drupalGet('/oauth/authorize', [
      'query' => $query,
    ]);
    if ($this->getSession()->getPage()->findButton('Allow') !== NULL) {
      $this->submitForm([], 'Allow');
    }

    $currentUrl = $this->getSession()->getCurrentUrl();
    $query = Query::parse((string) (parse_url($currentUrl, PHP_URL_QUERY) ?? ''));
    $this->assertArrayHasKey('code', $query);

    return ['code' => $query['code'], 'verifier' => $this->codeVerifier];
  }

  /**
   * Exchanges one authorization code for tokens.
   */
  private function exchangeCode(string $code, string $verifier): Response {
    $response = $this->post($this->url, [
      'grant_type' => 'authorization_code',
      'client_id' => $this->client->getClientId(),
      'code' => $code,
      'redirect_uri' => $this->redirectUri,
      'code_verifier' => $verifier,
    ]);
    \assert($response instanceof Response);
    return $response;
  }

  /**
   * Returns visible tool names for one access token.
   */
  private function toolNames(string $token, int $requestId): array {
    $response = $this->requestMcp('POST', $token, $this->modernHeaders('tools/list'), $this->modernBody($requestId, 'tools/list'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    return array_column($this->decodeJson($response)['result']['tools'], 'name');
  }

  /**
   * Returns modern-revision request headers.
   */
  private function modernHeaders(string $method = 'server/discover', string $version = self::MODERN_VERSION, ?string $name = NULL): array {
    $headers = [
      'Accept' => 'application/json, text/event-stream',
      'Content-Type' => 'application/json',
      McpHeader::PROTOCOL_VERSION => $version,
      McpHeader::METHOD => $method,
    ];
    if ($name !== NULL) {
      $headers[McpHeader::NAME] = $name;
    }
    return $headers;
  }

  /**
   * Builds a modern-revision JSON-RPC request body.
   */
  private function modernBody(int $id, string $method, ?string $toolName = NULL, string $version = self::MODERN_VERSION): string {
    $params = [
      '_meta' => [
        'io.modelcontextprotocol/protocolVersion' => $version,
        'io.modelcontextprotocol/clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
        'io.modelcontextprotocol/clientCapabilities' => [],
      ],
    ];
    if ($toolName !== NULL) {
      $params['name'] = $toolName;
      $params['arguments'] = [];
    }
    return Json::encode([
      'jsonrpc' => '2.0',
      'id' => $id,
      'method' => $method,
      'params' => $params,
    ]);
  }

  /**
   * Decodes a JSON response after asserting it is valid.
   */
  private function decodeJson(Response $response): array {
    $decoded = Json::decode((string) $response->getBody());
    $this->assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Asserts a response cannot be stored by shared or private caches.
   */
  private function assertPrivateNoStore(Response $response): void {
    $cacheControl = $response->getHeaderLine('Cache-Control');
    $this->assertStringContainsString('private', $cacheControl);
    $this->assertStringContainsString('no-store', $cacheControl);
  }

  /**
   * Decodes an access-token JWT payload without trusting its claims.
   */
  private function jwtClaims(string $token): array {
    $payload = explode('.', $token)[1] ?? '';
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $claims = Json::decode(base64_decode(strtr($payload, '-_', '+/'), TRUE) ?: '');
    $this->assertIsArray($claims);
    return $claims;
  }

}
