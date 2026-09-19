<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Mcp\Exception\ToolCallException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Psr\Log\LoggerInterface;

/**
 * Collects tool providers and filters tools for the current caller.
 *
 * Filtering happens on every request: a filtered catalogue is never cached
 * across users, tokens, or consumers.
 */
final class ToolRegistry {

  /**
   * Initializes the registry.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory providing enabled tool-family settings.
   * @param \Drupal\drupal_mcp\Mcp\ToolProviderInterface[] $providers
   *   Tagged tool provider services.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for denied tool executions.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly iterable $providers,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Lists the tool definitions visible to an account.
   *
   * A tool is visible when its family is enabled in settings, the token has
   * the scope and account permission required by its operation capability,
   * and the account holds every additional Drupal permission. Visibility only
   * shapes
   * tools/list: execution re-checks access again.
   *
   * @return \Drupal\drupal_mcp\Mcp\ToolDefinition[]
   *   Definitions keyed by tool name.
   */
  public function toolsForAccount(AccountInterface $account): array {
    if (!$account instanceof TokenAuthUser) {
      return [];
    }

    $settings = $this->configFactory->get('drupal_mcp.settings');
    $grantedScopes = [];
    foreach ($account->getToken()->get('scopes')->getScopes() as $scope) {
      $grantedScopes[] = $scope->getName();
    }

    $tools = [];
    foreach ($this->providers as $provider) {
      foreach ($provider->tools() as $definition) {
        if (!(bool) ($settings->get($definition->family->settingsKey()) ?? FALSE)) {
          continue;
        }
        $capability = $definition->capability;
        $requiredScope = $capability->resolveScope($this->configFactory);
        if (!\in_array($requiredScope, $grantedScopes, TRUE)
          || !$account->hasPermission($capability->permission())) {
          continue;
        }
        foreach ($definition->extraPermissions as $permission) {
          if (!$account->hasPermission($permission)) {
            continue 2;
          }
        }
        $tools[$definition->name] = $this->withErrorAudit($definition);
      }
    }
    return $tools;
  }

  /**
   * Wraps a handler so unexpected failures are audited with their message.
   *
   * The wrapper passes the caller through with the argument bag; the
   * ServerFactory binds the caller into the SDK-facing adapter afterwards.
   * The wrapper never leaks details to the client; it only records what the
   * SDK would otherwise swallow.
   */
  private function withErrorAudit(ToolDefinition $definition): ToolDefinition {
    $inner = $definition->handler;
    $logger = $this->logger;
    $name = $definition->name;

    $wrapper = function (array $arguments, TokenAuthUser $caller) use ($inner, $logger, $name): mixed {
      try {
        return $inner($arguments, $caller);
      }
      catch (ToolCallException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        $logger->error('MCP tool "@tool" failed unexpectedly with @type.', [
          '@tool' => $name,
          '@type' => $e::class,
        ]);
        throw $e;
      }
    };

    return $definition->withHandler($wrapper);
  }

}
