# Patch 4 — `simple-oauth-token-permissions-user-cache-context.patch`

- Package: `drupal/simple_oauth` 6.1.1 (see `composer.lock`)
- File: `patches/simple-oauth-token-permissions-user-cache-context.patch` (39 lines)
- Description in `composer.json`: "Vary token permission calculation by authenticated user"
- Provenance: **locally authored** (`patches.lock.json` `"provenance": "root"`).
- Target: `src/Access/DecoratedUserRolesAccessPolicy.php`

## What it does

Minimal hardening (+9/−4, plus +5/−1 and +5/−3 hunks): adds the `user` cache
context everywhere this decorator calculates permissions —

- all three `calculatePermissions()` branches (config-sync early return,
  non-`TokenAuthUserInterface` delegation to inner, and the main
  `TokenAuthUser` path that reloads full roles from the subject): each result
  gets `->addCacheContexts(['user'])`;
- `getPersistentCacheContexts()` merges `['user']` into the inner policy's
  contexts (deduped).

## Why it is needed

This decorator (introduced for SA-CONTRIB-2025-114) bypasses the role-scope
limiter and recalculates the token user's full role permissions. Without a
`user` cache context, a calculated-permissions result computed for user A can
be served from cache for user B on the same consumer/scope — cross-user
permission leakage (privilege escalation one way, wrongful denial the other).
Smallest and lowest-risk of the four patches. Pinned by
`OauthPermissionIsolationKernelTest::testScopeCeilingAndCrossUserIsolation`
(one scope stays a ceiling; permission caches vary by user).

## Third-party source

None — no upstream issue publishes this fix. Related background reading only:

- https://www.drupal.org/project/simple_oauth/issues/3507450 — 6.0 missing
  cache context + access policy support (the refactor this class came from)
- https://www.drupal.org/project/simple_oauth/issues/3569279 —
  `DecoratedUserRolesAccessPolicy` registered twice
- https://www.drupal.org/project/simple_oauth/issues/3572695 —
  `calculatePermissions()` vs `alterPermissions()`
- https://www.drupal.org/project/simple_oauth/issues/3588717 — fatal during
  deployment originating in this class
- https://www.drupal.org/project/simple_oauth/issues/3573262 — cache max-age 0
  behavior in the sibling `Oauth2AccessPolicy`

## Removal condition

A Simple OAuth release that varies token permission calculation by user (or
documents why its contexts are safe without it); then drop after
`OauthPermissionIsolationKernelTest` passes unpatched.
