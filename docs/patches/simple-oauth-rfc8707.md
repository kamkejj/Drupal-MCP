# Patch 2 — `simple-oauth-rfc8707.patch`

- Package: `drupal/simple_oauth` 6.1.1 (see `composer.lock`)
- File: `patches/simple-oauth-rfc8707.patch` (47 lines)
- Description in `composer.json`: "Propagate RFC 8707 resource restrictions into access-token JWTs"
- Provenance: **locally authored** (`patches.lock.json` `"provenance": "root"`).

## What it does

Small glue patch (+11/−2) on `src/Entities/AccessTokenEntity.php`, the Simple
OAuth side of the contract introduced by patch 1:

- `AccessTokenEntity` now `implements ResourceRestrictedTokenInterface` and
  uses `ResourceRestrictedTokenTrait` (alongside the existing
  `AccessTokenTrait`, `TokenEntityTrait`, etc.).
- In the JWT builder, after existing claims: reads `getResources()` and, when
  non-empty, adds `withClaim('resource', $resources)` — so the authorized
  resource set lands in the signed access token as a `resource` claim.
- Also adds `declare(strict_types=1)`.

When no resources were authorized the claim is omitted, preserving the old
token shape for flows that never send `resource`.

## Why it is needed

Without it, patch 1's League machinery has no cooperating entity: resources
are resolved but never serialized into the JWT, so every token stays unbound
and the MCP `require` policy rejects it. Conversely this patch alone does
nothing without patch 1 feeding `setResources()`. The pair is verified end to
end: authorize with `resource=https://…/mcp` → token carries that exact
`resource` claim → `/mcp` accepts; missing/different `resource` → `403`
(`scripts/test-mcp-oauth-flow.sh`,
`McpEndpointFunctionalTest` lines ~187/250).

## Third-party source

None. No Simple OAuth issue for RFC 8707 integration exists yet — filing it
is an owed follow-up recorded in `docs/oauth-resource-binding-gap.md`
("Upstream follow-up"). References:

- Project: https://www.drupal.org/project/simple_oauth
- Pinned source: https://git.drupalcode.org/project/simple_oauth/-/blob/6.1.1/src/Entities/AccessTokenEntity.php (unpatched upstream file)
- Spec: https://www.rfc-editor.org/info/rfc8707/

## Removal condition

A released Simple OAuth that propagates League resource restrictions into
access-token JWTs (together with a released League supporting RFC 8707 —
patch 1's exit condition); then drop both together after the OAuth flow
script, functional suite, and conformance clients pass unpatched.
