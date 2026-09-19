<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\drupal_mcp\Traits\TokenCallerTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests MCP entity tools against real Drupal access control and fields.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class EntityToolAccessKernelTest extends KernelTestBase {

  use TokenCallerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
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
    'drupal_mcp_test',
  ];

  /**
   * A user holding only the MCP read permission.
   */
  private UserInterface $reader;

  /**
   * A user with broad administrative permissions.
   */
  private UserInterface $administrator;

  /**
   * A node the test hooks allow everyone to view.
   */
  private NodeInterface $visibleNode;

  /**
   * A node the test hooks deny to every account.
   */
  private NodeInterface $deniedNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'drupal_mcp']);

    NodeType::create([
      'type' => 'mcp_test',
      'name' => 'MCP test',
    ])->save();
    $this->createField('field_public', 'string');
    $this->createField('field_private', 'string');
    $this->createField('field_secret_token', 'string');
    $this->createField('field_related', 'entity_reference', [
      'target_type' => 'node',
    ], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    Role::create([
      'id' => 'mcp_reader',
      'label' => 'MCP reader',
      'permissions' => [
        'access content',
        'access mcp read',
        'access user profiles',
      ],
    ])->save();
    Role::create([
      'id' => 'mcp_user_admin',
      'label' => 'MCP user administrator',
      'permissions' => [
        'access content',
        'access mcp read',
        'access user profiles',
        'administer users',
      ],
    ])->save();

    $this->createUser('root-placeholder', []);
    $this->reader = $this->createUser('reader', ['mcp_reader']);
    $this->administrator = $this->createUser('administrator', ['mcp_user_admin']);

    $this->visibleNode = Node::create([
      'type' => 'mcp_test',
      'title' => 'Visible reference',
      'status' => 1,
      'uid' => $this->administrator->id(),
      'field_public' => 'public value',
      'field_private' => 'private value',
      'field_secret_token' => 'token value',
    ]);
    $this->visibleNode->save();
    $this->deniedNode = Node::create([
      'type' => 'mcp_test',
      'title' => 'Denied reference',
      'status' => 1,
      'uid' => $this->administrator->id(),
    ]);
    $this->deniedNode->save();

    $this->visibleNode->set('field_related', [
      ['target_id' => $this->visibleNode->id()],
      ['target_id' => $this->deniedNode->id()],
    ])->save();

    $this->container->get('state')->set('drupal_mcp_test.denied_entities', [
      'node:' . $this->deniedNode->id(),
    ]);
    $this->container->get('state')->set('drupal_mcp_test.denied_fields', [
      'field_private',
      'field_secret_token',
    ]);
    $this->resetEntityAccessCaches();
    $this->container->get('config.factory')->getEditable('drupal_mcp.settings')
      ->set('node_bundles', ['mcp_test'])
      ->save();
  }

  /**
   * Tests list and get operations enforce per-entity view access.
   */
  public function testContentToolsFilterDeniedEntities(): void {
    $list = $this->callTool('Drupal\\drupal_mcp\\Tool\\ContentToolProvider', 'drupal_content_list', [
      'type' => 'mcp_test',
      'limit' => 10,
    ]);

    $this->assertSame(1, $list['count']);
    $this->assertSame([(string) $this->visibleNode->id()], array_column($list['items'], 'id'));

    // A view-denied node whose bundle is outside the read allowlist gets the
    // access denial, never the allowlist answer, so existence and exposure
    // state cannot be probed one id at a time.
    NodeType::create(['type' => 'unlisted', 'name' => 'Unlisted'])->save();
    $unlistedNode = Node::create([
      'type' => 'unlisted',
      'title' => 'Unlisted and denied',
      'status' => 1,
      'uid' => $this->administrator->id(),
    ]);
    $unlistedNode->save();
    $this->container->get('state')->set('drupal_mcp_test.denied_entities', [
      'node:' . $this->deniedNode->id(),
      'node:' . $unlistedNode->id(),
    ]);
    $this->resetEntityAccessCaches();

    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\ContentToolProvider', 'drupal_content_get', [
        'id' => (int) $unlistedNode->id(),
      ]);
      $this->fail('Expected an inaccessible node rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame(
        sprintf('Content item %d is not accessible.', (int) $unlistedNode->id()),
        $exception->getMessage(),
      );
    }

    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage(sprintf('Content item %d is not accessible.', $this->deniedNode->id()));
    $this->callTool('Drupal\\drupal_mcp\\Tool\\ContentToolProvider', 'drupal_content_get', [
      'id' => (int) $this->deniedNode->id(),
    ]);
  }

  /**
   * Tests projection enforces field access and reference target access.
   */
  public function testContentProjectionWithholdsFieldsAndReferences(): void {
    $result = $this->callTool('Drupal\\drupal_mcp\\Tool\\ContentToolProvider', 'drupal_content_get', [
      'id' => (int) $this->visibleNode->id(),
    ]);

    $this->assertSame(['public value'], $result['fields']['field_public']);
    $this->assertArrayNotHasKey('field_private', $result['fields']);
    $this->assertContains('field_private', $result['fields_withheld']);
    $this->assertArrayNotHasKey('field_secret_token', $result['fields']);
    $this->assertNotContains('field_secret_token', $result['fields_withheld']);
    $this->assertSame([
      [
        'target_type' => 'node',
        'target_id' => (string) $this->visibleNode->id(),
        'label' => 'Visible reference',
      ],
    ], $result['fields']['field_related']);
  }

  /**
   * Tests entity metadata honors field-level view access.
   */
  public function testContentMetadataHonorsFieldAccess(): void {
    $this->container->get('state')->set('drupal_mcp_test.denied_fields', [
      'title',
      'status',
      'created',
      'changed',
    ]);

    $result = $this->callTool('Drupal\\drupal_mcp\\Tool\\ContentToolProvider', 'drupal_content_get', [
      'id' => (int) $this->visibleNode->id(),
    ]);

    $this->assertSame('node', $result['item']['entity_type']);
    $this->assertSame((string) $this->visibleNode->id(), $result['item']['id']);
    $this->assertArrayNotHasKey('label', $result['item']);
    $this->assertArrayNotHasKey('status', $result['item']);
    $this->assertArrayNotHasKey('created', $result['item']);
    $this->assertArrayNotHasKey('changed', $result['item']);
  }

  /**
   * Tests user tools enforce entity access and safe caller-based projection.
   */
  public function testUserToolsEnforceAccessAndAdministrativeProjection(): void {
    $deniedUser = $this->createUser('denied-user', ['mcp_reader']);
    $this->container->get('state')->set('drupal_mcp_test.denied_entities', [
      'node:' . $this->deniedNode->id(),
      'user:' . $deniedUser->id(),
    ]);
    $this->resetEntityAccessCaches();

    $list = $this->callTool('Drupal\\drupal_mcp\\Tool\\UserToolProvider', 'drupal_users_list', [
      'name_prefix' => 'denied',
      'limit' => 10,
    ]);
    $this->assertSame(0, $list['count']);

    $readerView = $this->callTool('Drupal\\drupal_mcp\\Tool\\UserToolProvider', 'drupal_user_get', [
      'id' => (int) $this->administrator->id(),
    ]);
    $this->assertSame('administrator', $readerView['name']);
    $this->assertArrayNotHasKey('roles', $readerView);
    $this->assertArrayNotHasKey('status', $readerView);
    $this->assertArrayNotHasKey('mail', $readerView);

    $administratorView = $this->callTool('Drupal\\drupal_mcp\\Tool\\UserToolProvider', 'drupal_user_get', [
      'id' => (int) $this->reader->id(),
    ], $this->administrator);
    $this->assertSame(['authenticated', 'mcp_reader'], $administratorView['roles']);
    $this->assertSame(1, $administratorView['status']);
    $this->assertArrayNotHasKey('mail', $administratorView);
    $this->assertArrayNotHasKey('pass', $administratorView);
  }

  /**
   * Creates one configurable node field.
   */
  private function createField(string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'mcp_test',
      'label' => $name,
    ])->save();
  }

  /**
   * Creates an active user with the supplied roles.
   */
  private function createUser(string $name, array $roles): UserInterface {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Calls a named tool through its real provider service.
   */
  private function callTool(string $providerClass, string $toolName, array $arguments, ?UserInterface $as = NULL): array {
    $provider = $this->container->get($providerClass);
    foreach ($provider->tools() as $tool) {
      if ($tool->name === $toolName) {
        return ($tool->handler)($arguments, $this->tokenCaller($as ?? $this->reader));
      }
    }
    $this->fail(sprintf('Tool %s was not registered by %s.', $toolName, $providerClass));
  }

  /**
   * Resets handlers that may have cached access during fixture creation.
   */
  private function resetEntityAccessCaches(): void {
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('user')->resetCache();
  }

}
