<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\user\UserInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Entity\AccessibleEntityPager;
use Drupal\drupal_mcp\Mcp\Limits;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccessibleEntityPager $pager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      new ToolDefinition(
        name: 'drupal_users_list',
        title: 'List users',
        description: 'Pages through user accounts, exposing id and display name only. Requires "access user profiles".',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'cursor' => ['type' => 'string', 'description' => 'Opaque cursor returned by the previous page.'],
            'limit' => ['type' => 'integer', 'minimum' => 1],
            'name_prefix' => ['type' => 'string'],
          ],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->usersList(
          $args['cursor'] ?? NULL,
          isset($args['limit']) ? (int) $args['limit'] : NULL,
          $args['name_prefix'] ?? NULL,
        ),
        family: 'users',
        extraPermissions: ['access user profiles'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_user_get',
        title: 'Read user',
        description: 'Returns one user account: id, display name, created/changed dates, and language. Roles and account status are included only for callers with "administer users". Email and credential fields are never exposed.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) ['id' => ['type' => 'integer', 'minimum' => 1]],
          'required' => ['id'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->userGet((int) $args['id']),
        family: 'users',
        extraPermissions: ['access user profiles'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function usersList(?string $cursor, ?int $limit, ?string $namePrefix): array {
    $pageLimit = Limits::clamp($this->configFactory, $limit);
    $storage = $this->entityTypeManager->getStorage('user');
    $page = $this->pager->page(
      $storage,
      $this->currentUser->getAccount(),
      $cursor,
      $pageLimit,
      json_encode(['users', $namePrefix], JSON_THROW_ON_ERROR),
      function (int $offset, int $length) use ($storage, $namePrefix): array {
        $query = $storage->getQuery()
          ->accessCheck(TRUE)
          // The anonymous pseudo-account (uid 0) is a storage artifact, not a
          // listable account.
          ->condition('uid', 0, '>')
          ->sort('uid', 'ASC')
          ->range($offset, $length);
        if ($namePrefix !== NULL) {
          $query->condition('name', $namePrefix . '%', 'LIKE');
        }
        return $query->execute();
      },
      fn ($user): array => ['id' => (int) $user->id(), 'name' => (string) $user->getDisplayName()],
    );
    return ['limit' => $pageLimit] + $page;
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function userGet(int $id): array {
    $user = $this->entityTypeManager->getStorage('user')->load($id);
    if ($user === NULL) {
      throw new ToolCallException(sprintf('User %d does not exist.', $id));
    }
    \assert($user instanceof UserInterface);
    $account = $this->currentUser->getAccount();
    if (!$user->access('view', $account)) {
      throw new ToolCallException(sprintf('User %d is not accessible.', $id));
    }

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
    if ($account->hasPermission('administer users')) {
      $data['roles'] = array_values($user->getRoles());
      $data['status'] = $user->isActive() ? 1 : 0;
    }
    return $data;
  }

}
