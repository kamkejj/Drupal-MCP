<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\system\Entity\Menu;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Tests\drupal_mcp\Traits\TokenCallerTrait;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the read tool providers without direct handler coverage.
 *
 * Covers the block, taxonomy read, navigation, file, and entity-schema
 * handlers against real Drupal access control, so the recurring list/get
 * scaffolding can be reshaped behind them.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class ReadToolSurfaceKernelTest extends KernelTestBase {

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
    'path',
    'path_alias',
    'block',
    'block_content',
    'link',
    'taxonomy',
    'menu_link_content',
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
   * A node the test hooks allow everyone to view.
   */
  private Node $visibleNode;

  /**
   * A node the test hooks deny to every account.
   */
  private Node $deniedNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('taxonomy_vocabulary');
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'block_content', 'taxonomy', 'drupal_mcp']);

    NodeType::create([
      'type' => 'mcp_test',
      'name' => 'MCP test',
    ])->save();
    Role::create([
      'id' => 'mcp_reader',
      'label' => 'MCP reader',
      'permissions' => [
        'access content',
        'access mcp read',
        'access user profiles',
      ],
    ])->save();
    $this->createUser('root-placeholder', []);
    $this->reader = $this->createUser('reader', ['mcp_reader']);

    $this->visibleNode = Node::create([
      'type' => 'mcp_test',
      'title' => 'Visible page',
      'status' => 1,
      'uid' => $this->reader->id(),
    ]);
    $this->visibleNode->save();
    $this->deniedNode = Node::create([
      'type' => 'mcp_test',
      'title' => 'Denied page',
      'status' => 1,
      'uid' => $this->reader->id(),
    ]);
    $this->deniedNode->save();

    Vocabulary::create([
      'vid' => 'tags',
      'name' => 'Tags',
    ])->save();
    Vocabulary::create([
      'vid' => 'internal',
      'name' => 'Internal',
    ])->save();
    $termDenied = Term::create(['vid' => 'tags', 'name' => 'Denied tag']);
    $termDenied->save();
    Term::create(['vid' => 'internal', 'name' => 'Hidden tag'])->save();

    $visibleBlock = BlockContent::create([
      'type' => 'basic',
      'info' => 'Visible block',
      'reusable' => 1,
    ]);
    $visibleBlock->save();
    $deniedBlock = BlockContent::create([
      'type' => 'basic',
      'info' => 'Denied block',
      'reusable' => 1,
    ]);
    $deniedBlock->save();

    PathAlias::create(['path' => '/node/' . $this->visibleNode->id(), 'alias' => '/visible'])->save();
    PathAlias::create(['path' => '/node/' . $this->deniedNode->id(), 'alias' => '/secret'])->save();

    $file = File::create([
      'filename' => 'report.txt',
      'filemime' => 'text/plain',
      'filesize' => 128,
      'status' => 1,
      'uri' => 'public://report.txt',
    ]);
    $file->save();
    $deniedFile = File::create([
      'filename' => 'secret.txt',
      'filemime' => 'text/plain',
      'filesize' => 64,
      'status' => 1,
      'uri' => 'public://secret.txt',
    ]);
    $deniedFile->save();
    $fileUsage = $this->container->get('file.usage');
    $fileUsage->add($file, 'drupal_mcp_test', 'node', (int) $this->visibleNode->id(), 1);
    $fileUsage->add($file, 'drupal_mcp_test', 'node', (int) $this->deniedNode->id(), 1);

    Menu::create(['id' => 'mcp_menu', 'label' => 'MCP menu'])->save();
    MenuLinkContent::create([
      'title' => 'Visible link',
      'link' => ['uri' => 'entity:node/' . $this->visibleNode->id()],
      'menu_name' => 'mcp_menu',
      'enabled' => 1,
    ])->save();
    MenuLinkContent::create([
      'title' => 'Denied link',
      'link' => ['uri' => 'entity:node/' . $this->deniedNode->id()],
      'menu_name' => 'mcp_menu',
      'enabled' => 1,
    ])->save();

    $this->container->get('state')->set('drupal_mcp_test.denied_entities', [
      'node:' . $this->deniedNode->id(),
      'block_content:' . $deniedBlock->id(),
      'taxonomy_term:' . $termDenied->id(),
      'file:' . $deniedFile->id(),
    ]);
    $this->container->get('config.factory')->getEditable('drupal_mcp.settings')
      ->set('node_bundles', ['mcp_test'])
      ->set('vocabularies', ['tags'])
      ->save();
  }

  /**
   * Tests block list/get tools enforce access and project fields.
   */
  public function testBlockToolsEnforceAccessAndProjection(): void {
    $list = $this->callTool('Drupal\\drupal_mcp\\Tool\\BlockToolProvider', 'drupal_blocks_list', [
      'type' => 'basic',
      'limit' => 10,
    ]);
    $this->assertSame(1, $list['count']);
    $this->assertCount(1, $list['items']);
    $this->assertArrayNotHasKey('uuid', $list['items'][0]);
    $this->assertNull($list['next_cursor']);

    $visible = BlockContent::load(1);
    $this->assertNotNull($visible);
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\BlockToolProvider', 'drupal_block_get', [
        'id' => 999,
      ]);
      $this->fail('Expected a nonexistent block rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('Block 999 does not exist.', $exception->getMessage());
    }

    $denied = $this->container->get('entity_type.manager')->getStorage('block_content')->loadByProperties(['info' => 'Denied block']);
    $deniedBlock = reset($denied);
    $this->assertInstanceOf(BlockContent::class, $deniedBlock);
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\BlockToolProvider', 'drupal_block_get', [
        'id' => (int) $deniedBlock->id(),
      ]);
      $this->fail('Expected an inaccessible block rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame(sprintf('Block %d is not accessible.', (int) $deniedBlock->id()), $exception->getMessage());
    }

    $accessibles = $this->container->get('entity_type.manager')->getStorage('block_content')->loadByProperties(['info' => 'Visible block']);
    $accessibleBlock = reset($accessibles);
    $result = $this->callTool('Drupal\\drupal_mcp\\Tool\\BlockToolProvider', 'drupal_block_get', [
      'id' => (int) $accessibleBlock->id(),
    ]);
    $this->assertSame('block_content', $result['item']['entity_type']);
    $this->assertArrayHasKey('fields', $result);
    $this->assertArrayHasKey('fields_withheld', $result);
  }

  /**
   * Tests vocabulary and term tools enforce allowlist and per-term access.
   */
  public function testTaxonomyReadToolsEnforceAllowlistAndAccess(): void {
    $vocabularies = $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_vocabularies_list', []);
    $byId = [];
    foreach ($vocabularies['vocabularies'] as $vocabulary) {
      $byId[$vocabulary['id']] = $vocabulary;
    }
    $this->assertArrayHasKey('tags', $byId);
    $this->assertTrue($byId['tags']['exposed_to_mcp']);
    $this->assertArrayHasKey('internal', $byId);
    $this->assertFalse($byId['internal']['exposed_to_mcp']);

    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_vocabulary_get', ['vid' => 'missing']);
      $this->fail('Expected an unknown vocabulary rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('Unknown vocabulary "missing".', $exception->getMessage());
    }

    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_terms_list', ['vid' => 'internal']);
      $this->fail('Expected an unexposed vocabulary rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('Vocabulary "internal" is not exposed through MCP.', $exception->getMessage());
    }

    $terms = $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_terms_list', [
      'vid' => 'tags',
      'name_contains' => 'Denied',
      'limit' => 10,
    ]);
    $this->assertSame(0, $terms['count']);
    $this->assertSame('tags', $terms['vid']);
    $this->assertArrayHasKey('limit', $terms);

    $deniedTerms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['name' => 'Denied tag']);
    $deniedTerm = reset($deniedTerms);
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_term_get', [
        'id' => (int) $deniedTerm->id(),
      ]);
      $this->fail('Expected an inaccessible term rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame(sprintf('Term %d is not accessible.', (int) $deniedTerm->id()), $exception->getMessage());
    }

    $hiddenTerms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['name' => 'Hidden tag']);
    $hiddenTerm = reset($hiddenTerms);
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_term_get', [
        'id' => (int) $hiddenTerm->id(),
      ]);
      $this->fail('Expected an unexposed vocabulary rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('This vocabulary is not exposed through MCP.', $exception->getMessage());
    }

    Term::create(['vid' => 'tags', 'name' => 'Visible tag'])->save();
    $visibleTerms = $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_terms_list', [
      'vid' => 'tags',
      'limit' => 10,
    ]);
    $this->assertSame(1, $visibleTerms['count']);
    $this->assertSame('Visible tag', $visibleTerms['items'][0]['name']);

    $visibleTermId = (int) $visibleTerms['items'][0]['id'];
    $term = $this->callTool('Drupal\\drupal_mcp\\Tool\\TaxonomyToolProvider', 'drupal_term_get', [
      'id' => $visibleTermId,
    ]);
    $this->assertSame('taxonomy_term', $term['item']['entity_type']);
    $this->assertArrayHasKey('fields', $term);
  }

  /**
   * Tests menu and alias tools filter inaccessible targets.
   */
  public function testNavigationToolsFilterInaccessibleTargets(): void {
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\NavigationToolProvider', 'drupal_menu_get', ['menu' => 'missing']);
      $this->fail('Expected an unknown menu rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('Unknown menu "missing".', $exception->getMessage());
    }

    $menu = $this->callTool('Drupal\\drupal_mcp\\Tool\\NavigationToolProvider', 'drupal_menu_get', ['menu' => 'mcp_menu']);
    $this->assertSame('mcp_menu', $menu['menu']);
    $this->assertSame(1, $menu['link_count']);
    $this->assertSame('Visible link', $menu['links'][0]['title']);
    $this->assertSame('/visible', $menu['links'][0]['url']);

    $aliases = $this->callTool('Drupal\\drupal_mcp\\Tool\\NavigationToolProvider', 'drupal_aliases_list', [
      'alias_prefix' => '/vis',
      'limit' => 10,
    ]);
    // Alias entities only resolve view access for their administrators, which
    // matches the tool's declared extra permission.
    Role::create([
      'id' => 'mcp_alias_admin',
      'label' => 'MCP alias administrator',
      'permissions' => [
        'access content',
        'access mcp read',
        'administer url aliases',
      ],
    ])->save();
    $aliasAdmin = $this->createUser('alias-admin', ['mcp_alias_admin']);

    $aliases = $this->callTool('Drupal\\drupal_mcp\\Tool\\NavigationToolProvider', 'drupal_aliases_list', [
      'alias_prefix' => '/vis',
      'limit' => 10,
    ], $aliasAdmin);
    $this->assertSame(1, $aliases['count']);
    $this->assertSame('/visible', $aliases['items'][0]['alias']);

    $all = $this->callTool('Drupal\\drupal_mcp\\Tool\\NavigationToolProvider', 'drupal_aliases_list', [
      'limit' => 10,
    ], $aliasAdmin);
    $this->assertSame(1, $all['count']);
    $this->assertSame('/visible', $all['items'][0]['alias']);
  }

  /**
   * Tests file metadata projection and usage target filtering.
   */
  public function testFileGetProjectsMetadataAndFiltersUsage(): void {
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\FileToolProvider', 'drupal_file_get', ['id' => 999]);
      $this->fail('Expected a nonexistent file rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('File 999 does not exist.', $exception->getMessage());
    }

    $deniedFiles = $this->container->get('entity_type.manager')->getStorage('file')->loadByProperties(['filename' => 'secret.txt']);
    $deniedFile = reset($deniedFiles);
    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\FileToolProvider', 'drupal_file_get', [
        'id' => (int) $deniedFile->id(),
      ]);
      $this->fail('Expected an inaccessible file rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame(sprintf('File %d is not accessible.', (int) $deniedFile->id()), $exception->getMessage());
    }

    $accessibleFiles = $this->container->get('entity_type.manager')->getStorage('file')->loadByProperties(['filename' => 'report.txt']);
    $file = reset($accessibleFiles);
    $result = $this->callTool('Drupal\\drupal_mcp\\Tool\\FileToolProvider', 'drupal_file_get', [
      'id' => (int) $file->id(),
    ]);
    $this->assertSame('report.txt', $result['filename']);
    $this->assertTrue($result['status_permanent']);
    $this->assertSame([
      [
        'module' => 'drupal_mcp_test',
        'entity_type' => 'node',
        'id' => (string) $this->visibleNode->id(),
        'count' => 1,
      ],
    ], $result['usage']);
  }

  /**
   * Tests structural schema tools expose definitions, not records.
   */
  public function testEntitySchemaToolsListStructure(): void {
    $types = $this->callTool('Drupal\\drupal_mcp\\Tool\\EntitySchemaToolProvider', 'drupal_entity_types_list', ['group' => 'content']);
    $ids = array_column($types['entity_types'], 'id');
    $this->assertContains('node', $ids);
    $this->assertNotContains('node_type', $ids);

    try {
      $this->callTool('Drupal\\drupal_mcp\\Tool\\EntitySchemaToolProvider', 'drupal_schema_get', ['entity_type' => 'consumer']);
      $this->fail('Expected an uninspectable entity type rejection.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('Entity type "consumer" is not inspectable.', $exception->getMessage());
    }

    $schema = $this->callTool('Drupal\\drupal_mcp\\Tool\\EntitySchemaToolProvider', 'drupal_schema_get', ['entity_type' => 'node']);
    $this->assertContains('mcp_test', array_column($schema['bundles'], 'id'));

    $fields = $this->callTool('Drupal\\drupal_mcp\\Tool\\EntitySchemaToolProvider', 'drupal_fields_list', [
      'entity_type' => 'node',
      'bundle' => 'mcp_test',
    ]);
    $this->assertContains('title', array_column($fields['fields'], 'name'));
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

}
