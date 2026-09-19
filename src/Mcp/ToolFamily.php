<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

/**
 * The tool families this module can expose, and their settings keys.
 *
 * A family is the unit of exposure in drupal_mcp.settings: read families
 * toggle under "families.<id>" while mutation families toggle under
 * "mutation_families.<id>". This enum is the single mapping between the
 * family declared on a tool definition and the settings key the registry
 * reads, so adding a family never requires registry changes and a family
 * name can never drift out of sync with its configuration.
 */
enum ToolFamily: string {

  case Site = 'site';
  case EntitySchema = 'entity_schema';
  case Content = 'content';
  case Taxonomy = 'taxonomy';
  case Blocks = 'blocks';
  case Menus = 'menus';
  case Aliases = 'aliases';
  case Files = 'files';
  case Users = 'users';
  case Drush = 'drush';
  case Database = 'database';
  case TaxonomyMutation = 'taxonomy_mutation';
  case NodeMutation = 'node_mutation';

  /**
   * The drupal_mcp.settings key that enables or disables this family.
   */
  public function settingsKey(): string {
    return match ($this) {
      self::TaxonomyMutation => 'mutation_families.taxonomy',
      self::NodeMutation => 'mutation_families.node',
      default => 'families.' . $this->value,
    };
  }

}
