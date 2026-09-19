<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\Core\Session\AccountInterface;

/**
 * The persistence boundary for MCP entity mutations.
 *
 * One implementation owns the complete policy for one entity type:
 * enablement, access, field normalization, validation, revisioning, and
 * audit. Callers pass the invoking account and a normalized command array
 * and receive a projection array; every expected failure is a
 * MutationException whose message is safe for the client. Tool providers
 * depend on this interface, never on a concrete mutator.
 */
interface EntityMutatorInterface {

  /**
   * Creates one entity from a command array.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account invoking the mutation; access is evaluated for it.
   * @param array<string, mixed> $command
   *   The normalized tool arguments.
   *
   * @return array<string, mixed>
   *   The approved result projection.
   *
   * @throws \Drupal\drupal_mcp\Mutation\MutationException
   *   When policy, access, or validation denies the mutation.
   */
  public function create(AccountInterface $account, array $command): array;

  /**
   * Updates one entity under an optimistic revision precondition.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account invoking the mutation; access is evaluated for it.
   * @param array<string, mixed> $command
   *   The normalized tool arguments, including the expected revision.
   *
   * @return array<string, mixed>
   *   The approved result projection.
   *
   * @throws \Drupal\drupal_mcp\Mutation\MutationException
   *   When policy, access, validation, or the revision check fails.
   */
  public function update(AccountInterface $account, array $command): array;

  /**
   * Deletes one entity under exact target preconditions.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account invoking the mutation; access is evaluated for it.
   * @param array<string, mixed> $command
   *   The normalized tool arguments, including target confirmation.
   *
   * @return array<string, mixed>
   *   The approved result projection.
   *
   * @throws \Drupal\drupal_mcp\Mutation\MutationException
   *   When deletion is unsupported, disabled, or denied.
   */
  public function delete(AccountInterface $account, array $command): array;

}
