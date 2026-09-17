# Patch 3 — `simple-oauth-rfc7009-jwt-revocation.patch`

- Package: `e0ipso/simple_oauth_21` v1.13.0 (see `composer.lock`)
- File: `patches/simple-oauth-rfc7009-jwt-revocation.patch` (109 lines)
- Description in `composer.json`: "Resolve revoked tokens by their stored identifier (JWT jti or encrypted payload)"
- Provenance: **locally authored** (`patches.lock.json` `"provenance": "root"`).
- Target: `modules/simple_oauth_server_metadata/src/Service/TokenRevocationService.php`

## What it does

Fixes the RFC 7009 revocation endpoint (`/oauth/revoke`) so revoking a real
token actually marks it revoked (+73/−4, plus +24/−0 and +9/−2 hunks):

- `revoke()` now decrypts the presented value first (`tokenClaims()`) and
  passes the claims into `findToken($tokenValue, $claims)`.
- New `tokenIdentifier()`: derives the **stored** identifier from what the
  client presented —
  - JWT access tokens (3 dot-segments): base64url-decode the payload, return
    the `jti` claim, because `oauth2_token.value` stores the `jti`, not the
    full JWT string;
  - encrypted refresh tokens: return `refresh_token_id` (or `access_token_id`)
    from inside the decrypted ciphertext;
  - anything else (opaque strings): returned unchanged, so existing lookups
    keep working.
- New `tokenClaims()`: only attempts decryption for `def502`-prefixed hex
  input (prefix + hex check before the expensive key derivation), uses the
  site hash salt via Defuse Crypto, returns `NULL` on any failure so the
  entity lookup simply misses.
- Ownership fix: refresh-token entities store no client reference, so when
  `validateOwnership()` fails for them, the code falls back to comparing the
  `client_id` *inside the authenticated ciphertext payload* — the same value
  the refresh grant itself compares — instead of hard `FALSE`.

## Why it is needed

Without it, revoking a JWT access token looks up the full JWT string in
`oauth2_token.value`, finds nothing, and — per RFC 7009's "unknown token =
success" rule — returns success **without revoking anything**. The token
stays valid and `/mcp` keeps returning `200` for it. Refresh revocation fails
the ownership check outright. The patch makes revoke-then-use return `401`
(access) / `400`-on-refresh as pinned by
`McpEndpointFunctionalTest` (revocation assertions ~lines 275–371) and
`scripts/test-mcp-denials.sh` step 7.

## Third-party source

None. No matching upstream issue exists; the repo's open issues are unrelated
(scheme matching, absolute-URL export, localization, OIDC claims). Repo and
tracker (file upstream here if pursued):

- https://github.com/e0ipso/simple_oauth_21/
- https://github.com/e0ipso/simple_oauth_21/issues
- Unrelated open issues surveyed 2026-09-16:
  https://github.com/e0ipso/simple_oauth_21/issues/19,
  https://github.com/e0ipso/simple_oauth_21/issues/21,
  https://github.com/e0ipso/simple_oauth_21/issues/16,
  https://github.com/e0ipso/simple_oauth_21/issues/14

## Removal condition

An upstream release that resolves presented tokens to their stored identifier
and validates refresh ownership against the payload; then drop after the
functional revocation assertions and `test-mcp-denials.sh` pass unpatched.
