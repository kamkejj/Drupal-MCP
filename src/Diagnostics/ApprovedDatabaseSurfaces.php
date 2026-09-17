<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

/**
 * The server-defined catalogue of approvable database inspection surfaces.
 *
 * A surface becomes callable when its view name is additionally enabled in
 * the drupal_mcp.settings "database_surfaces" list (fail closed). The views
 * are curated projections over the Drupal schema that deliberately exclude
 * sensitive rows and columns:
 *
   * - users_public: no mail, password, init, timezone, or session data.
   * - taxonomy_public: default-language term data only.
 */
final class ApprovedDatabaseSurfaces {

  /**
   * Executes the operation.
   *
   * @return array<string, \Drupal\drupal_mcp\Diagnostics\DatabaseSurface>
   *   Keyed by view name.
   */
  public static function all(): array {
    return [
      'users_public' => new DatabaseSurface(
        view: 'users_public',
        label: 'User accounts (public projection)',
        description: 'Active-site user accounts: id, name, created, last access, status. Mail addresses, password data, and session/credential columns are excluded by the view.',
        columns: [
          'uid' => 'int',
          'name' => 'string',
          'created' => 'int',
          'access' => 'int',
          'status' => 'int',
        ],
        requiredPermissions: ['administer users'],
      ),
      'taxonomy_public' => new DatabaseSurface(
        view: 'taxonomy_public',
        label: 'Taxonomy terms (public projection)',
        description: 'Default-language taxonomy term data: id, vocabulary, name, weight, changed, status.',
        columns: [
          'tid' => 'int',
          'vid' => 'string',
          'name' => 'string',
          'weight' => 'int',
          'changed' => 'int',
          'status' => 'int',
        ],
        requiredPermissions: ['administer taxonomy'],
      ),
    ];
  }

}
