<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
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
    $families = $settings->get('families') ?? [];
    // Mutation families are intentionally separate from read exposure.
    $families['taxonomy_mutation'] = (bool) $settings->get('mutation_families.taxonomy');
    $families['node_mutation'] = (bool) $settings->get('mutation_families.node');

    $tools = [];
    foreach ($this->providers as $provider) {
      foreach ($provider->tools() as $definition) {
        if (!($families[$definition->family] ?? FALSE)) {
          continue;
        }
        $capability = $definition->capability;
        $requiredScope = (string) ($settings->get($capability->scopeConfigKey()) ?: $capability->defaultScope());
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
   * Returns a single visible tool definition by name, or NULL.
   *
   * Used by the execution path so that calling a hidden or disabled tool
   * directly fails closed.
   */
  public function toolForAccount(AccountInterface $account, string $name): ?ToolDefinition {
    return $this->toolsForAccount($account)[$name] ?? NULL;
  }

  /**
   * Wraps a handler so unexpected failures are audited with their message.
   *
   * The wrapper is also bound to the SDK ReferenceHandler scope so the SDK
   * hands it the raw argument bag instead of mapping closure parameters by
   * name against tool arguments. The wrapper never leaks details to the
   * client; it only records what the SDK would otherwise swallow.
   */
  private function withErrorAudit(ToolDefinition $definition): ToolDefinition {
    $inner = $definition->handler;
    $logger = $this->logger;
    $name = $definition->name;

    $wrapper = function (array $arguments) use ($inner, $logger, $name): mixed {
      try {
        return $inner($arguments);
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

    return new ToolDefinition(
      $definition->name,
      $definition->title,
      $definition->description,
      $definition->inputSchema,
      \Closure::bind($wrapper, NULL, ReferenceHandler::class),
      $definition->family,
      $definition->extraPermissions,
      $definition->annotations,
      $definition->capability,
    );
  }

}
