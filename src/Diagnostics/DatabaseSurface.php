<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

use Drupal\Core\Session\AccountInterface;

/**
 * One approved, curated database inspection surface.
 *
 * Surfaces are code-defined curated views created by
 * scripts/provision-db-readonly.sh in the dedicated mcp_inspect schema.
 * Sensitive rows and columns are excluded at the database layer; the
 * SELECT-only principal cannot reach anything but these views.
 */
final class DatabaseSurface {

  /**
   * Executes the operation.
   *
   * @param string $view
   *   View name inside the mcp_inspect schema.
   * @param string $label
   *   Human label.
   * @param string $description
   *   What the view contains and what was excluded.
   * @param array<string, string> $columns
   *   Selectable/sortable column => type description. The projection and
   *   filter allowlists are exactly these names.
   * @param list<string> $requiredPermissions
   *   Native Drupal permissions required to use this privileged surface.
   */
  public function __construct(
    public readonly string $view,
    public readonly string $label,
    public readonly string $description,
    public readonly array $columns,
    public readonly array $requiredPermissions,
  ) {}

  /**
   * Checks whether the effective OAuth account may use this surface.
   */
  public function allows(AccountInterface $account): bool {
    foreach ($this->requiredPermissions as $permission) {
      if (!$account->hasPermission($permission)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
