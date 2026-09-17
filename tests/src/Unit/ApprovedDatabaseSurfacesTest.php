<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Diagnostics\ApprovedDatabaseSurfaces;
use PHPUnit\Framework\TestCase;

/**
 * Tests the curated database inspection catalogue.
 */
final class ApprovedDatabaseSurfacesTest extends TestCase {

  /**
   * Tests that curated database surfaces expose no sensitive columns.
   */
  public function testCuratedSurfacesExposeNoSensitiveColumns(): void {
    $surfaces = ApprovedDatabaseSurfaces::all();

    $this->assertSame(
      ['users_public', 'taxonomy_public'],
      array_keys($surfaces),
    );

    $forbiddenFragments = [
      'pass',
      'mail',
      'token',
      'session',
      'secret',
      'credential',
      'private',
    ];
    foreach ($surfaces as $name => $surface) {
      $columnNames = strtolower(implode(' ', array_keys($surface->columns)));
      foreach ($forbiddenFragments as $fragment) {
        $this->assertStringNotContainsString($fragment, $columnNames, $name);
      }
    }
  }

}
