<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Tests\drupal_mcp\Traits\TokenCallerTrait;
use Drupal\drupal_mcp\Mutation\NodeMutationException;
use Drupal\drupal_mcp\Mutation\NodeMutator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests node mutations against Drupal's real entity and access systems.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class NodeMutationKernelTest extends KernelTestBase {

  use TokenCallerTrait;

  /**
   * Excludes settings from strict schema checks.
   *
   * The node mutation config keys land with the tool-surface change, so their
   * schema is not yet present in this branch.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['drupal_mcp.settings'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'options',
    'node',
    'taxonomy',
    'serialization',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'drupal_mcp',
    'drupal_mcp_test',
  ];

  /**
   * The mutation service under test. */
  private NodeMutator $mutator;

  /**
   * The authorized node writer. */
  private UserInterface $writer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'drupal_mcp']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_mcp_text',
      'type' => 'text',
      'cardinality' => 1,
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_mcp_tags',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_mcp_number',
      'type' => 'integer',
      'cardinality' => 1,
    ])->save();
    foreach (['article', 'page'] as $type) {
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $type,
        'field_name' => 'field_mcp_text',
      ])->save();
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $type,
        'field_name' => 'field_mcp_tags',
        'settings' => ['handler_settings' => ['target_bundles' => ['tags' => 'tags']]],
      ])->save();
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $type,
        'field_name' => 'field_mcp_number',
      ])->save();
    }

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'other', 'name' => 'Other'])->save();
    foreach (['mcp_text', 'mcp_private'] as $format) {
      FilterFormat::create([
        'format' => $format,
        'name' => $format,
        'weight' => 0,
        'filters' => [],
      ])->save();
    }
    Role::create([
      'id' => 'mcp_writer',
      'label' => 'MCP writer',
      'permissions' => [
        'access content',
        'access mcp write',
        'create article content',
        'edit own article content',
        'use text format mcp_text',
      ],
    ])->save();
    $this->createUser('root-placeholder', []);
    $this->writer = $this->createUser('writer', ['mcp_writer']);
    $this->container->get('config.factory')->getEditable('drupal_mcp.settings')
      ->set('mutation_families.node', TRUE)
      ->set('writable_node_bundles', ['article'])
      ->save();
    $this->mutator = new NodeMutator(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('lock'),
      $this->container->get('logger.channel.drupal_mcp'),
    );
  }

  /**
   * Tests create projection, configured fields, and configuration gates.
   */
  public function testCreateAndConfigurationGates(): void {
    $term = $this->term('Tagged');
    $created = $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => ' Hello ',
      'field_mcp_text' => ['value' => '<em>safe</em>', 'format' => 'mcp_text'],
      'field_mcp_tags' => [(int) $term->id()],
      'field_mcp_number' => 7,
    ]);
    $this->assertSame('Hello', $created['fields']['title']);
    $this->assertSame(
      ['value' => '<em>safe</em>', 'format' => 'mcp_text'],
      $created['fields']['field_mcp_text'],
    );
    $this->assertSame([(int) $term->id()], $created['fields']['field_mcp_tags']);
    $this->assertSame(7, $created['fields']['field_mcp_number']);
    $this->assertSame('article', $created['type']);
    $saved = Node::load($created['id']);
    $this->assertInstanceOf(NodeInterface::class, $saved);
    $this->assertSame('Hello', $saved->getTitle());

    $this->assertCreateFailure('target_not_writable', ['type' => 'page', 'title' => 'No']);
    $this->assertCreateFailure('target_not_writable', ['type' => 'missing', 'title' => 'No']);
    $this->config('drupal_mcp.settings')->set('mutation_families.node', FALSE)->save();
    $this->assertCreateFailure('mutation_disabled', ['type' => 'article', 'title' => 'Disabled']);
  }

  /**
   * Tests the protected base-field denylist and unknown fields.
   */
  public function testProtectedBaseFieldDenylist(): void {
    foreach (['status', 'uid', 'created', 'promote', 'sticky', 'revision_log', 'path'] as $baseField) {
      $this->assertCreateFailure('field_not_writable', [
        'type' => 'article',
        'title' => 'Denied',
        $baseField => 1,
      ]);
    }
    $this->assertCreateFailure('field_not_writable', [
      'type' => 'article',
      'title' => 'Unknown',
      'field_mcp_missing' => 'x',
    ]);
    $this->assertCreateFailure('invalid_value', ['type' => 'article', 'title' => ' ']);
  }

  /**
   * Tests native create/update access and edit field access.
   */
  public function testNativeAndFieldAccessDenials(): void {
    $unprivileged = $this->createUser('unprivileged', []);
    $this->assertCreateFailure('entity_access_denied', ['type' => 'article', 'title' => 'Denied'], $unprivileged);

    $node = $this->node('Existing');
    $this->assertFailure('entity_access_denied', fn () => $this->mutator->update($this->caller($unprivileged), [
      'id' => (int) $node->id(),
      'expected_revision_id' => (int) $node->getRevisionId(),
      'changes' => ['title' => 'Denied update'],
    ]));

    // A caller without update access on a non-writable bundle gets the access
    // denial, never the allowlist or revision answer.
    $page = Node::create([
      'type' => 'page',
      'title' => 'Unwritable page',
      'uid' => $this->writer->id(),
      'status' => 1,
    ]);
    $page->save();
    $this->assertFailure('entity_access_denied', fn () => $this->mutator->update($this->caller($unprivileged), [
      'id' => (int) $page->id(),
      'expected_revision_id' => 1,
      'changes' => ['title' => 'Probe'],
    ]));

    $this->container->get('state')->set('drupal_mcp_test.denied_fields', ['field_mcp_text']);
    $this->container->get('state')->set('drupal_mcp_test.denied_field_operations', ['edit']);
    $this->resetAccessCaches();
    $this->assertFailure('field_not_writable', fn () => $this->mutator->update($this->caller(), [
      'id' => (int) $node->id(),
      'expected_revision_id' => (int) $node->getRevisionId(),
      'changes' => ['field_mcp_text' => ['value' => 'Blocked', 'format' => 'mcp_text']],
    ]));
  }

  /**
   * Tests malformed values and formatted text format permissions.
   */
  public function testFormattedTextPermissions(): void {
    $this->assertFailure('invalid_text_format', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Missing format',
      'field_mcp_text' => ['value' => 'x', 'format' => 'missing'],
    ]));
    $this->assertFailure('invalid_text_format', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Malformed format',
      'field_mcp_text' => ['value' => 'x'],
    ]));
    $this->assertFailure('invalid_text_format', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Unauthorized format',
      'field_mcp_text' => ['value' => 'x', 'format' => 'mcp_private'],
    ]));
  }

  /**
   * Tests entity reference validity, bundle targeting, and view access.
   */
  public function testEntityReferenceRules(): void {
    $term = $this->term('Tagged');
    $foreign = $this->term('Foreign', 'other');
    $this->assertFailure('invalid_reference', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Missing target',
      'field_mcp_tags' => [999999],
    ]));
    $this->assertFailure('invalid_reference', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Wrong bundle',
      'field_mcp_tags' => [(int) $foreign->id()],
    ]));
    $this->assertFailure('invalid_reference', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Malformed refs',
      'field_mcp_tags' => 'tags',
    ]));

    $this->container->get('state')->set('drupal_mcp_test.denied_entities', ['taxonomy_term:' . $term->id()]);
    $this->container->get('state')->set('drupal_mcp_test.denied_entity_operations', ['view']);
    $this->resetAccessCaches();
    $this->assertFailure('invalid_reference', fn () => $this->mutator->create($this->caller(), [
      'type' => 'article',
      'title' => 'Hidden target',
      'field_mcp_tags' => [(int) $term->id()],
    ]));
  }

  /**
   * Tests update preconditions, no-op detection, and failed-update atomicity.
   */
  public function testUpdateRevisionAndAtomicity(): void {
    $node = $this->node('Original');
    $id = (int) $node->id();
    $revision = (int) $node->getRevisionId();
    $this->assertUpdateFailure('not_found', [
      'id' => 999999,
      'expected_revision_id' => 999999,
      'changes' => ['title' => 'x'],
    ]);
    $this->assertUpdateFailure('revision_conflict', [
      'id' => $id,
      'expected_revision_id' => $revision + 1,
      'changes' => ['title' => 'x'],
    ]);
    $this->assertUpdateFailure('revision_conflict', ['id' => $id, 'changes' => ['title' => 'x']]);
    $this->assertUpdateFailure('no_changes', [
      'id' => $id,
      'expected_revision_id' => $revision,
      'changes' => ['title' => 'Original'],
    ]);
    $this->assertUpdateFailure('invalid_value', [
      'id' => $id,
      'expected_revision_id' => $revision,
      'changes' => [],
    ]);

    $this->assertFailure('invalid_reference', fn () => $this->mutator->update($this->caller(), [
      'id' => $id,
      'expected_revision_id' => $revision,
      'changes' => ['title' => 'Must not leak', 'field_mcp_tags' => [999999]],
    ]));
    $reloaded = Node::load($id);
    $this->assertInstanceOf(NodeInterface::class, $reloaded);
    $this->assertSame('Original', $reloaded->getTitle());

    $updated = $this->mutator->update($this->caller(), [
      'id' => $id,
      'expected_revision_id' => $revision,
      'changes' => ['title' => 'Updated', 'field_mcp_number' => 3],
    ]);
    $this->assertSame('Updated', $updated['fields']['title']);
    $this->assertSame(3, $updated['fields']['field_mcp_number']);
    $this->assertGreaterThan($revision, $updated['revision_id']);
    $latest = $this->container->get('entity_type.manager')
      ->getStorage('node')->loadRevision($updated['revision_id']);
    $this->assertInstanceOf(NodeInterface::class, $latest);
    $this->assertSame('Updated by Drupal MCP node mutation.', $latest->getRevisionLogMessage());
  }

  /**
   * Creates and saves a node fixture authored by the writer. */
  private function node(string $title): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'uid' => $this->writer->id(),
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates and saves a taxonomy term fixture. */
  private function term(string $name, string $vocabulary = 'tags') {
    $term = Term::create(['vid' => $vocabulary, 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Returns the explicit mutation caller, defaulting to the writer. */
  private function caller(?UserInterface $as = NULL) {
    return $this->tokenCaller($as ?? $this->writer);
  }

  /**
   * Creates and saves a user fixture. */
  private function createUser(string $name, array $roles): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Asserts a categorized mutation failure. */
  private function assertFailure(string $category, callable $operation): void {
    try {
      $operation();
      $this->fail(sprintf('Expected node mutation failure "%s".', $category));
    }
    catch (NodeMutationException $exception) {
      $this->assertSame($category, $exception->category);
    }
  }

  /**
   * Asserts a categorized create failure. */
  private function assertCreateFailure(string $category, array $command, ?UserInterface $as = NULL): void {
    $this->assertFailure($category, fn () => $this->mutator->create($this->caller($as), $command));
  }

  /**
   * Asserts a categorized update failure. */
  private function assertUpdateFailure(string $category, array $command, ?UserInterface $as = NULL): void {
    $this->assertFailure($category, fn () => $this->mutator->update($this->caller($as), $command));
  }

  /**
   * Resets node and term access caches. */
  private function resetAccessCaches(): void {
    foreach (['node', 'taxonomy_term'] as $entityTypeId) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($entityTypeId)->resetCache();
      $this->container->get('entity_type.manager')->getStorage($entityTypeId)->resetCache();
    }
  }

}
