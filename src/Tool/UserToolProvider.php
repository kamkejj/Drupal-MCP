<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\drupal_mcp\Entity\EntityReadTools;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\user\UserInterface;

/**
 * User inspection tools.
 *
 * Safe field projection only: email, password hashes, reset/login tokens,
 * and session data are never exposed, even to administrators. Role
 * membership and account status appear only to callers with the native
 * user-administration permission.
 */
final class UserToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly EntityReadTools $reads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      $this->reads->listTool(
        name: 'drupal_users_list',
        title: 'List users',
        description: 'Pages through user accounts, exposing id and display name only. Requires "access user profiles".',
        family: ToolFamily::Users,
        entityTypeId: 'user',
        contextSeed: 'users',
        filters: [
          'name_prefix' => [
            'schema' => ['type' => 'string'],
            'field' => 'name',
            'operator' => EntityReadTools::OP_STARTS_WITH,
          ],
        ],
        project: EntityReadTools::labelProject(),
        extraPermissions: ['access user profiles'],
        // The anonymous pseudo-account (uid 0) is a storage artifact, not a
        // listable account.
        fixedConditions: [['uid', 0, '>']],
      ),
      $this->reads->getTool(
        name: 'drupal_user_get',
        title: 'Read user',
        description: 'Returns one user account: id, display name, created/changed dates, and language. Roles and account status are included only for callers with "administer users". Email and credential fields are never exposed.',
        family: ToolFamily::Users,
        entityTypeId: 'user',
        label: 'User',
        extraPermissions: ['access user profiles'],
        project: fn ($user, TokenAuthUser $caller): array => $this->projectUser($user, $caller),
      ),
    ];
  }

  /**
   * Projects one user account without credential or email fields.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function projectUser(UserInterface $user, TokenAuthUser $caller): array {
    // Email requires an explicit field policy that does not exist yet; it is
    // deliberately omitted for every caller. Credential/token fields are
    // never exposed at all.
    $data = [
      'id' => (int) $user->id(),
      'name' => (string) $user->getDisplayName(),
      'created' => (int) $user->getCreatedTime(),
      'changed' => (int) $user->getChangedTime(),
      'langcode' => $user->getPreferredLangcode(),
    ];
    if ($caller->hasPermission('administer users')) {
      $data['roles'] = array_values($user->getRoles());
      $data['status'] = $user->isActive() ? 1 : 0;
    }
    return $data;
  }

}
