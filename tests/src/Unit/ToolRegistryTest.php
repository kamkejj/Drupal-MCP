<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolRegistry;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\AccountStub;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ToolProviderStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Tests per-request tool visibility.
 */
final class ToolRegistryTest extends TestCase {

  /**
   * Tests non-token accounts fail closed and definitions default to read.
   */
  public function testNonTokenAccountsFailClosedAndCapabilityDefaultsToRead(): void {
    $settings = new ConfigFactoryStub([
      'drupal_mcp.settings' => [
        'families' => ['drush' => TRUE, 'database' => FALSE],
      ],
    ]);
    $provider = new ToolProviderStub([
      new ToolDefinition('visible', 'Visible', 'Visible tool.', [], fn (array $arguments, TokenAuthUser $caller): array => [], ToolFamily::Drush),
      new ToolDefinition(
        'missing_native_permission',
        'Missing native permission',
        'Missing native permission.',
        [],
        fn (array $arguments, TokenAuthUser $caller): array => [],
        ToolFamily::Drush,
        ['access comments'],
      ),
      new ToolDefinition('disabled_family', 'Disabled', 'Disabled family.', [], fn (array $arguments, TokenAuthUser $caller): array => [], ToolFamily::Database),
      new ToolDefinition(
        'missing_extra_permission',
        'Missing extra permission',
        'Missing extra permission.',
        [],
        fn (array $arguments, TokenAuthUser $caller): array => [],
        ToolFamily::Drush,
        ['administer filters'],
      ),
    ]);
    $registry = new ToolRegistry($settings, [$provider], new NullLogger());

    $this->assertSame(OperationCapability::Read, $provider->tools()[0]->capability);
    $this->assertSame([], $registry->toolsForAccount(AccountStub::withPermissions(
      'access mcp read',
      'administer site configuration',
    )));
    $this->assertSame([], $registry->toolsForAccount(AccountStub::withPermissions('authenticated content')));
    $this->assertNull($registry->toolsForAccount(
      AccountStub::withPermissions('access mcp read', 'administer site configuration'),
    )['missing_extra_permission'] ?? NULL);
  }

}
