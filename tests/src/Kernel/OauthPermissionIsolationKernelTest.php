<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolRegistry;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;

/**
 * Tests scope ceilings and cross-user permission-cache isolation.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class OauthPermissionIsolationKernelTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['system', 'user', 'simple_oauth', 'drupal_mcp']);
  }

  /**
   * Tests one scope remains a ceiling and permission caches vary by user.
   */
  public function testScopeCeilingAndCrossUserIsolation(): void {
    Role::create([
      'id' => 'mcp_read_ceiling',
      'label' => 'MCP read ceiling',
      'permissions' => [
        'access mcp read',
        'access user profiles',
        'administer users',
      ],
    ])->save();
    Role::create([
      'id' => 'mcp_reader',
      'label' => 'MCP reader',
      'permissions' => [
        'access mcp read',
        'access user profiles',
      ],
    ])->save();
    Role::create([
      'id' => 'mcp_admin',
      'label' => 'MCP administrator',
      'permissions' => [
        'access mcp read',
        'access user profiles',
        'administer users',
        'administer site configuration',
      ],
    ])->save();

    Oauth2Scope::create([
      'id' => 'mcp_read',
      'name' => 'mcp:read',
      'description' => 'MCP read access',
      'granularity_id' => 'role',
      'granularity_configuration' => ['role' => 'mcp_read_ceiling'],
    ])->save();

    $consumer = Consumer::create([
      'label' => 'Kernel test client',
      'client_id' => 'kernel-test-client',
      'third_party' => TRUE,
    ]);
    $consumer->save();

    $root = User::create([
      'name' => 'root-placeholder',
      'mail' => 'root-placeholder@example.com',
      'status' => 1,
    ]);
    $root->save();

    $reader = $this->createTokenAccount('reader', 'mcp_reader', $consumer);
    $administrator = $this->createTokenAccount('administrator', 'mcp_admin', $consumer);
    $permissionChecker = $this->container->get('permission_checker');
    $scopeProvider = $this->container->get('simple_oauth.oauth2_scope.provider');
    $scope = $scopeProvider->loadByName('mcp:read');
    $this->assertInstanceOf(Oauth2ScopeInterface::class, $scope);
    $this->assertSame('mcp_read', $scope->id());
    $this->assertSame('mcp:read', $scope->getName());
    $this->assertNotSame($reader->id(), $administrator->id());
    $this->assertNotSame(1, (int) $reader->id(), 'The reader must not be Drupal user 1.');
    $this->assertNotSame(1, (int) $administrator->id(), 'The administrator must not be Drupal user 1.');
    $this->assertTrue($permissionChecker->hasPermission('administer users', $administrator));
    $this->assertFalse($permissionChecker->hasPermission('administer site configuration', $administrator));
    // Simulate the next request by clearing both request-local and persistent
    // access-policy caches. Persistent cache variation is asserted below.
    $this->resetAccessPolicyCaches();
    $this->assertTrue($permissionChecker->hasPermission('access mcp read', $reader));
    $this->assertFalse($permissionChecker->hasPermission('administer users', $reader));
    $this->resetAccessPolicyCaches();
    $this->assertTrue($permissionChecker->hasPermission('administer users', $administrator));
    $this->resetAccessPolicyCaches();
    $this->assertFalse($permissionChecker->hasPermission('administer users', $reader));

    $roleContexts = $this->container->get('simple_oauth.access_policy.user_roles')->getPersistentCacheContexts();
    $this->assertContains('user', $roleContexts);
    $scopeContexts = $this->container->get('access_policy.simple_oauth')->getPersistentCacheContexts();
    $this->assertSame(['oauth2_scopes'], $scopeContexts);
  }

  /**
   * Tests capability scope and permission pairs filter listing and execution.
   */
  public function testToolCapabilitiesRequireMatchingScopeAndPermission(): void {
    Role::create([
      'id' => 'mcp_both_ceiling',
      'label' => 'MCP capability ceiling',
      'permissions' => ['access mcp read', 'access mcp write'],
    ])->save();
    foreach (['read', 'write', 'both', 'neither'] as $kind) {
      Role::create([
        'id' => "mcp_$kind",
        'label' => "MCP $kind",
        'permissions' => match ($kind) {
          'read' => ['access mcp read'],
          'write' => ['access mcp write'],
          'both' => ['access mcp read', 'access mcp write'],
          default => [],
        },
      ])->save();
    }
    foreach (['read', 'write'] as $kind) {
      Oauth2Scope::create([
        'id' => "mcp_$kind",
        'name' => "mcp:$kind",
        'description' => "MCP $kind access",
        'granularity_id' => 'role',
        'granularity_configuration' => ['role' => 'mcp_both_ceiling'],
      ])->save();
    }

    $consumer = Consumer::create([
      'label' => 'Capability test client',
      'client_id' => 'capability-test-client',
      'third_party' => TRUE,
    ]);
    $consumer->save();
    $accounts = [
      'read' => $this->createTokenAccount('cap-read', 'mcp_read', $consumer, ['mcp_read']),
      'write' => $this->createTokenAccount('cap-write', 'mcp_write', $consumer, ['mcp_write']),
      'both' => $this->createTokenAccount('cap-both', 'mcp_both', $consumer, ['mcp_read', 'mcp_write']),
      'neither' => $this->createTokenAccount('cap-neither', 'mcp_neither', $consumer, []),
    ];
    $provider = new class implements ToolProviderInterface {

      /**
       * {@inheritdoc}
       */
      public function tools(): array {
        return [
          new ToolDefinition('read_tool', 'Read', 'Reads.', [], fn (): array => [], 'site'),
          new ToolDefinition('write_tool', 'Write', 'Writes.', [], fn (): array => [], 'site', capability: OperationCapability::Write),
        ];
      }

    };
    $registry = new ToolRegistry($this->container->get('config.factory'), [$provider], new NullLogger());
    $accessPolicy = $this->container->get('drupal_mcp.access_policy');
    $this->assertInstanceOf(McpAccessPolicy::class, $accessPolicy);

    $this->assertTrue($accessPolicy->canAccessCapability($accounts['read'], OperationCapability::Read));
    $this->assertFalse($accessPolicy->canAccessCapability($accounts['read'], OperationCapability::Write));
    $this->assertTrue($accessPolicy->canAccessCapability($accounts['write'], OperationCapability::Write));
    $this->assertTrue($accessPolicy->canAccessCapability($accounts['both'], OperationCapability::Read));
    $this->assertTrue($accessPolicy->canAccessCapability($accounts['both'], OperationCapability::Write));
    $this->assertFalse($accessPolicy->canAccessCapability($accounts['neither'], OperationCapability::Read));
    $this->assertFalse($accessPolicy->canAccessCapability($accounts['neither'], OperationCapability::Write));

    $this->assertSame(['read_tool'], array_keys($registry->toolsForAccount($accounts['read'])));
    $this->assertSame(['write_tool'], array_keys($registry->toolsForAccount($accounts['write'])));
    $this->assertSame(['read_tool', 'write_tool'], array_keys($registry->toolsForAccount($accounts['both'])));
    $this->assertSame([], $registry->toolsForAccount($accounts['neither']));
    $this->assertNull($registry->toolForAccount($accounts['read'], 'write_tool'));
    $this->assertNull($registry->toolForAccount($accounts['write'], 'read_tool'));
  }

  /**
   * Clears permission results between simulated requests.
   */
  private function resetAccessPolicyCaches(): void {
    $this->container->get('cache.access_policy_memory')->deleteAll();
    $this->container->get('cache.access_policy')->deleteAll();
  }

  /**
   * Creates a user-backed token account for the shared read scope.
   */
  private function createTokenAccount(string $name, string $role, Consumer $consumer, array $scopeIds = ['mcp_read']): AccountInterface {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
      'roles' => [$role],
    ]);
    $user->save();

    $token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $user->id(),
      'client' => $consumer->id(),
      'scopes' => array_map(static fn (string $scopeId): array => ['scope_id' => $scopeId], $scopeIds),
      'value' => hash('sha256', $name),
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
