<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Diagnostics\DatabaseInspector;
use Drupal\drupal_mcp\Diagnostics\ReadOnlyDatabaseConnection;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\AccountStub;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests native permission enforcement for database surfaces.
 */
final class DatabaseInspectorAccessTest extends TestCase {

  /**
   * Tests the surface catalogue is filtered by native permissions.
   */
  public function testTablesOnlyListsSurfacesAllowedForCurrentAccount(): void {
    $inspector = $this->inspector(AccountStub::withPermissions('administer users'));

    $result = $inspector->tables();

    $this->assertSame(1, $result['count']);
    $this->assertSame(['users_public'], array_column($result['surfaces'], 'name'));
  }

  /**
   * Tests direct surface access cannot bypass catalogue filtering.
   */
  public function testDescribeRejectsSurfaceWithoutNativePermission(): void {
    $inspector = $this->inspector(AccountStub::withPermissions('administer users'));

    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('Database surface "taxonomy_public" is not approved.');
    $inspector->describe('taxonomy_public');
  }

  /**
   * Builds an inspector whose database connection is never opened.
   */
  private function inspector(AccountStub $account): DatabaseInspector {
    $accountProxy = $this->createStub(AccountProxyInterface::class);
    $accountProxy->method('getAccount')->willReturn($account);

    return new DatabaseInspector(
      new ReadOnlyDatabaseConnection(),
      new ConfigFactoryStub([
        'drupal_mcp.settings' => [
          'database_surfaces' => ['users_public', 'taxonomy_public'],
        ],
      ]),
      $accountProxy,
      new RequestStack(),
      new NullLogger(),
      $this->createStub(LockBackendInterface::class),
    );
  }

}
