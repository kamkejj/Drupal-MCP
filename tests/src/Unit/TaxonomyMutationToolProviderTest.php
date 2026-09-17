<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Idempotency\IdempotencyManager;
use Drupal\drupal_mcp\Mutation\TaxonomyTermMutator;
use Drupal\drupal_mcp\Tool\TaxonomyMutationToolProvider;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use PHPUnit\Framework\TestCase;

/**
 * Tests destructive taxonomy tool catalogue policy.
 */
final class TaxonomyMutationToolProviderTest extends TestCase {

  /**
   * Tests deletion is absent unless its independent switch is enabled.
   */
  public function testDeleteDefinitionRequiresDestructiveEnablement(): void {
    $disabled = $this->provider(FALSE)->tools();
    $this->assertNotContains('drupal_term_delete', array_column($disabled, 'name'));

    $enabled = $this->provider(TRUE)->tools();
    $definitions = [];
    foreach ($enabled as $definition) {
      $definitions[$definition->name] = $definition;
    }
    $this->assertArrayHasKey('drupal_term_delete', $definitions);
    $delete = $definitions['drupal_term_delete'];
    $this->assertTrue($delete->annotations->destructiveHint);
    $this->assertFalse($delete->annotations->idempotentHint);
    $this->assertSame(
      ['id', 'expected_revision_id', 'confirm_term_name'],
      $delete->inputSchema['required'],
    );
    $this->assertArrayNotHasKey('confirm', (array) $delete->inputSchema['properties']);
    $this->assertArrayNotHasKey('idempotency_key', (array) $delete->inputSchema['properties']);
  }

  /**
   * Builds a provider without invoking the unused final collaborators.
   */
  private function provider(bool $destructive): TaxonomyMutationToolProvider {
    $reflection = new \ReflectionClass(TaxonomyTermMutator::class);
    $mutator = $reflection->newInstanceWithoutConstructor();
    $reflection = new \ReflectionClass(IdempotencyManager::class);
    $idempotency = $reflection->newInstanceWithoutConstructor();

    return new TaxonomyMutationToolProvider(
      $mutator,
      $idempotency,
      $this->createMock(AccountProxyInterface::class),
      new ConfigFactoryStub([
        'drupal_mcp.settings' => [
          'destructive_mutations' => ['taxonomy_terms' => $destructive],
        ],
      ]),
    );
  }

}
