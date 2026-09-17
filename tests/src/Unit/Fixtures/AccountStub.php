<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit\Fixtures;

use Drupal\Core\Session\AccountInterface;

/**
 * Minimal account implementation for unit tests.
 */
final class AccountStub implements AccountInterface {

  private function __construct(
    private readonly array $permissions,
    private readonly int $id = 42,
  ) {}

  /**
   * Creates an account carrying the supplied permissions.
   */
  public static function withPermissions(string ...$permissions): self {
    return new self($permissions);
  }

  /**
   * Returns the account identifier.
   */
  public function id() {
    return $this->id;
  }

  /**
   * Returns the authenticated role.
   */
  public function getRoles($exclude_locked_roles = FALSE) {
    return ['authenticated'];
  }

  /**
   * Tests only the supplied permissions.
   */
  public function hasPermission(string $permission) {
    return \in_array($permission, $this->permissions, TRUE);
  }

  /**
   * Tests for the authenticated role.
   */
  public function hasRole(string $rid) {
    return $rid === 'authenticated';
  }

  /**
   * Marks the account authenticated.
   */
  public function isAuthenticated() {
    return TRUE;
  }

  /**
   * Marks the account not anonymous.
   */
  public function isAnonymous() {
    return FALSE;
  }

  /**
   * Returns English.
   */
  public function getPreferredLangcode($fallback_to_default = TRUE) {
    return 'en';
  }

  /**
   * Returns English.
   */
  public function getPreferredAdminLangcode($fallback_to_default = TRUE) {
    return 'en';
  }

  /**
   * Returns the account name.
   */
  public function getAccountName() {
    return 'test-user';
  }

  /**
   * Returns the display name.
   */
  public function getDisplayName() {
    return 'Test user';
  }

  /**
   * Returns no email address.
   */
  public function getEmail() {
    return NULL;
  }

  /**
   * Returns UTC.
   */
  public function getTimeZone() {
    return 'UTC';
  }

  /**
   * Returns no last-access time.
   */
  public function getLastAccessedTime() {
    return 0;
  }

}
