<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Traits;

use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Wraps kernel-test users as explicit token callers.
 *
 * Handlers receive the caller explicitly, so tests pass a TokenAuthUser the
 * way the endpoint would instead of swapping the account proxy. Token
 * permissions are the scope ceiling intersected with the user's own, so each
 * caller gets a scope whose ceiling role mirrors the user's permissions
 * exactly: the caller behaves like the account it wraps.
 */
trait TokenCallerTrait {

  /**
   * The shared consumer owning test tokens, lazily created.
   */
  private ?Consumer $callerConsumer = NULL;

  /**
   * Memoized callers keyed by user id.
   *
   * @var array<int, \Drupal\simple_oauth\Authentication\TokenAuthUser>
   */
  private array $callers = [];

  /**
   * Memoized scope ids keyed by the caller's role set.
   *
   * @var array<string, string>
   */
  private array $callerScopes = [];

  /**
   * Returns the user as a token-authenticated MCP caller.
   */
  protected function tokenCaller(UserInterface $user): TokenAuthUser {
    $uid = (int) $user->id();
    if (isset($this->callers[$uid])) {
      return $this->callers[$uid];
    }

    if ($this->callerConsumer === NULL) {
      $this->callerConsumer = Consumer::create([
        'label' => 'Kernel test caller client',
        'client_id' => 'kernel-test-caller',
        'third_party' => TRUE,
      ]);
      $this->callerConsumer->save();
    }

    $token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $uid,
      'client' => $this->callerConsumer->id(),
      'scopes' => [['scope_id' => $this->callerScope($user)]],
      'value' => hash('sha256', $user->getAccountName() . microtime()),
    ]);
    $token->save();

    // The endpoint authenticates the account into the global proxy as well;
    // core internals such as entity-reference validation still read it. The
    // MCP module itself only ever uses the explicit caller.
    \Drupal::service('current_user')->setAccount($user);

    return $this->callers[$uid] = new TokenAuthUser(
      \Drupal::service('permission_checker'),
      $token,
      \Drupal::service('psr7.http_message_factory'),
      \Drupal::service('request_stack'),
    );
  }

  /**
   * Returns a scope id whose permission ceiling matches the user's roles.
   */
  private function callerScope(UserInterface $user): string {
    $roles = $user->getRoles(TRUE);
    sort($roles);
    $key = implode(',', $roles);
    if (isset($this->callerScopes[$key])) {
      return $this->callerScopes[$key];
    }

    $permissions = [];
    foreach (Role::loadMultiple($roles) as $role) {
      $permissions = array_merge($permissions, $role->getPermissions());
    }
    $suffix = substr(hash('sha256', $key), 0, 10);
    Role::create([
      'id' => 'mcp_caller_ceiling_' . $suffix,
      'label' => sprintf('MCP caller ceiling %s', $suffix),
      'permissions' => array_values(array_unique($permissions)),
    ])->save();
    Oauth2Scope::create([
      'id' => 'mcp_caller_' . $suffix,
      'name' => 'mcp:caller-' . $suffix,
      'description' => 'Kernel-test caller scope mirroring the wrapped user.',
      'granularity_id' => 'role',
      'granularity_configuration' => ['role' => 'mcp_caller_ceiling_' . $suffix],
    ])->save();

    return $this->callerScopes[$key] = 'mcp_caller_' . $suffix;
  }

}
