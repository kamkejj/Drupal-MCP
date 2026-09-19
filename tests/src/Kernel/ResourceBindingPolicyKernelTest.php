<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the RFC 8707 resource-binding gate of the access policy.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class ResourceBindingPolicyKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'options',
    'serialization',
    'path_alias',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'drupal_mcp',
  ];

  /**
   * The canonical resource URI every bound token must name.
   */
  private const RESOURCE_URI = 'https://drupalmcp.ddev.site/mcp';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['system', 'user', 'simple_oauth', 'drupal_mcp']);
    $this->config('drupal_mcp.settings')->set('resource_uri', self::RESOURCE_URI)->save();
  }

  /**
   * Tests that "require" rejects every unverifiable resource binding.
   */
  public function testRequirePolicyRejectsUnverifiableBindings(): void {
    $this->config('drupal_mcp.settings')->set('resource_binding', 'require')->save();
    $account = $this->tokenAccount();
    $policy = $this->accessPolicy();

    // A correctly bound token passes.
    $bound = $this->requestWithJwt(['resource' => [self::RESOURCE_URI]]);
    $this->assertNull($policy->authorize($bound, $account));

    // A token naming another resource is rejected.
    $foreign = $this->requestWithJwt(['resource' => ['https://other.example.com/mcp']]);
    $this->assertForbidden('different resource', $policy->authorize($foreign, $account));

    // A decodable JWT with no resource information is rejected.
    $unbound = $this->requestWithJwt(['aud' => ['resource-binding-client']]);
    $this->assertForbidden('audience binding', $policy->authorize($unbound, $account));

    // An opaque token whose claims cannot be inspected is rejected, not
    // exempted from the check.
    $opaque = $this->requestWithAuthorization('Bearer not-a-jwt');
    $this->assertForbidden('audience binding', $policy->authorize($opaque, $account));

    // A token presented outside the Authorization header is equally
    // unverifiable and rejected.
    $this->assertForbidden('audience binding', $policy->authorize(Request::create('/mcp', 'POST'), $account));
  }

  /**
   * Tests that the interim "audit" policy still admits unbound tokens.
   */
  public function testAuditPolicyAdmitsUnboundTokens(): void {
    $this->config('drupal_mcp.settings')->set('resource_binding', 'audit')->save();
    $account = $this->tokenAccount();

    $opaque = $this->requestWithAuthorization('Bearer not-a-jwt');
    $this->assertNull($this->accessPolicy()->authorize($opaque, $account));
  }

  /**
   * Returns the access policy service under test.
   */
  private function accessPolicy(): McpAccessPolicy {
    $policy = $this->container->get('drupal_mcp.access_policy');
    \assert($policy instanceof McpAccessPolicy);
    return $policy;
  }

  /**
   * Builds a POST /mcp request carrying the given Authorization header value.
   *
   * @param string $header
   *   The raw Authorization header value, e.g. "Bearer <token>".
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request with the crafted header.
   */
  private function requestWithAuthorization(string $header): Request {
    $request = Request::create('/mcp', 'POST');
    $request->headers->set('Authorization', $header);
    return $request;
  }

  /**
   * Builds a request whose bearer token carries the given JWT claims.
   *
   * @param array<string, mixed> $claims
   *   Unverified payload claims for the bearer token. The access policy only
   *   inspects claims of tokens Simple OAuth has already validated.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request with the crafted Authorization header.
   */
  private function requestWithJwt(array $claims): Request {
    $payload = rtrim(strtr(base64_encode((string) json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return $this->requestWithAuthorization('Bearer header.' . $payload . '.signature');
  }

  /**
   * Asserts the denial response names the expected reason and is a 403.
   *
   * @param string $reason
   *   Substring expected in the denial's error description.
   * @param \Symfony\Component\HttpFoundation\Response|null $response
   *   The response returned by authorize().
   */
  private function assertForbidden(string $reason, ?Response $response): void {
    $this->assertInstanceOf(Response::class, $response);
    $decoded = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame('forbidden', $decoded['error']);
    $this->assertStringContainsString($reason, $decoded['error_description']);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Creates a read-capable token account for the authorize() gate.
   *
   * @return \Drupal\simple_oauth\Authentication\TokenAuthUser
   *   A caller whose token grants the mcp:read scope and whose user holds the
   *   matching Drupal permission.
   */
  private function tokenAccount(): TokenAuthUser {
    Role::create([
      'id' => 'mcp_read_ceiling',
      'label' => 'MCP read ceiling',
      'permissions' => ['access mcp read'],
    ])->save();
    Oauth2Scope::create([
      'id' => 'mcp_read',
      'name' => 'mcp:read',
      'description' => 'MCP read access',
      'granularity_id' => 'role',
      'granularity_configuration' => ['role' => 'mcp_read_ceiling'],
    ])->save();
    $user = User::create([
      'name' => 'resource-binding-caller',
      'mail' => 'resource-binding@example.com',
      'status' => 1,
      'roles' => ['mcp_read_ceiling'],
    ]);
    $user->save();
    $consumer = Consumer::create([
      'label' => 'Resource binding client',
      'client_id' => 'resource-binding-client',
      'third_party' => TRUE,
    ]);
    $consumer->save();
    $token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $user->id(),
      'client' => $consumer->id(),
      'scopes' => [['scope_id' => 'mcp_read']],
      'value' => hash('sha256', 'resource-binding'),
    ]);
    $token->save();
    return new TokenAuthUser(
      $this->container->get('permission_checker'),
      $token,
      $this->container->get('psr7.http_message_factory'),
      $this->container->get('request_stack'),
    );
  }

}
