<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Mutation\TaxonomyMutationException;
use Drupal\drupal_mcp\Mutation\TaxonomyTermMutator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests guarded taxonomy term deletion against real entity storage.
 */
#[Group('drupal_mcp')]
final class TaxonomyTermDeleteKernelTest extends KernelTestBase {

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
    'text',
    'filter',
    'taxonomy',
    'drupal_mcp',
  ];

  /**
   * The mutation boundary under test.
   */
  private TaxonomyTermMutator $mutator;

  /**
   * Mutable account proxy used to exercise native access.
   */
  private AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['system', 'user', 'filter', 'taxonomy', 'drupal_mcp']);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->container->get('config.factory')->getEditable('drupal_mcp.settings')
      ->set('mutation_families.taxonomy', TRUE)
      ->set('writable_vocabularies', ['tags'])
      ->set('destructive_mutations.taxonomy_terms', TRUE)
      ->save();
    $this->currentUser = $this->container->get('current_user');
    $this->mutator = new TaxonomyTermMutator(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->currentUser,
      $this->container->get('config.factory'),
      $this->container->get('lock'),
      $this->container->get('logger.channel.drupal_mcp'),
    );
  }

  /**
   * Tests native denial, target checks, references, and safe tombstones.
   */
  public function testDeleteSafeguardsAndTombstone(): void {
    Role::create(['id' => 'term_deleter', 'label' => 'Term deleter', 'permissions' => ['delete terms in tags']])->save();
    $allowed = User::create(['name' => 'allowed', 'status' => 1, 'roles' => ['term_deleter']]);
    $allowed->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'Secret label']);
    $term->save();
    $command = [
      'id' => (int) $term->id(),
      'expected_revision_id' => (int) $term->getRevisionId(),
      'confirm_term_name' => 'Secret label',
    ];

    $this->currentUser->setAccount($allowed);
    $this->assertFailure('revision_conflict', fn (): array => $this->mutator->delete(array_replace($command, ['expected_revision_id' => 999999])));
    $this->assertFailure('target_mismatch', fn (): array => $this->mutator->delete(array_replace($command, ['confirm_term_name' => 'Wrong'])));

    $child = Term::create(['vid' => 'tags', 'name' => 'Child', 'parent' => [['target_id' => $term->id()]]]);
    $child->save();
    $this->assertFailure('target_referenced', fn (): array => $this->mutator->delete($command));
    $child->delete();

    $result = $this->mutator->delete($command);
    $this->assertSame([
      'id' => (int) $term->id(),
      'vocabulary' => 'tags',
      'deleted_revision_id' => (int) $term->getRevisionId(),
      'status' => 'deleted',
    ], $result);
    $this->assertArrayNotHasKey('name', $result);
    $this->assertNull(Term::load($term->id()));
  }

  /**
   * Asserts a categorized safe failure.
   */
  private function assertFailure(string $category, callable $operation): void {
    try {
      $operation();
      $this->fail('Expected taxonomy mutation failure.');
    }
    catch (TaxonomyMutationException $exception) {
      $this->assertSame($category, $exception->category);
      $this->assertStringNotContainsString('Secret label', $exception->getMessage());
    }
  }

}
