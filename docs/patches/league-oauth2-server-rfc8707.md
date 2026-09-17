# Patch 1 — `league-oauth2-server-rfc8707.patch`

- Package: `league/oauth2-server` 9.4.1 (see `composer.lock`)
- File: `patches/league-oauth2-server-rfc8707.patch` (925 lines)
- Description in `composer.json`: "RFC 8707 resource indicators for authorization-code and refresh grants"
- Provenance: **locally authored** (`patches.lock.json` `"provenance": "root"`).
  Derived from open upstream PRs (see below) but not a verbatim copy.

## What it does

Backports RFC 8707 resource-indicator support to the installed 9.4.1 release,
covering the authorization-code grant (with PKCE) and refresh-token rotation:

- New `Entities/ResourceRestrictedTokenInterface.php` (+35) — opt-in contract
  for token entities that carry resource restrictions. Checked via
  `instanceof`, so existing consumers keep working unmodified.
- New `Entities/Traits/ResourceRestrictedTokenTrait.php` (+40) — default
  in-memory implementation (`getResources()` / `setResources()`).
- `Exception/OAuthServerException.php` (+19) — new `invalidTarget()` factory
  (`invalid_target`, HTTP 400) per RFC 8707 §2.
- `Grant/AbstractAuthorizeGrant.php` (+42) — new
  `applyResourcesToAuthorizationRequest()`; raises `LogicException` (fail fast
  in development) when the client sent `resource` but the authorization-request
  implementation cannot carry it.
- `Grant/AbstractGrant.php` (+189/−1) — the core:
  - `applyResourceIndicators()` — set restrictions on a built-but-unpersisted
    token; `LogicException` when the entity lacks the interface (a
    misconfiguration, not a protocol error), deliberately thrown *before*
    persistence so no orphaned token row is left behind.
  - `resolveTokenEndpointResources()` — RFC 8707 narrowing: token-endpoint
    `resource` values must be a subset of the authorized set, else
    `invalid_target`.
  - `parseResourceIndicators()` / `validateResourceIndicator()` — each entry
    must be an absolute URI without fragment; no whitespace trimming, so the
    authorize-time and token-time values compare byte-identical (RFC 3986
    §6.2.1 simple string comparison).
  - `getRawRequestParameter()` / `getRawQueryStringParameter()` — read
    repeatable `resource` params without coercion (string or array).
  - Splits `issueAccessToken()` into `buildAccessToken()` +
    `persistAccessToken()` so grants can configure the entity pre-persistence.
- `Grant/AuthCodeGrant.php` (+105/−2) — parse `resource` at `/authorize`,
  persist the set in the auth-code payload (`resources`), re-validate and
  narrow at `/token`; build → emit `RequestAccessTokenResourcesEvent` (deniable)
  → apply → persist. Malformed persisted payload aborts with `invalid_grant`
  rather than silently dropping a restriction.
- `Grant/RefreshTokenGrant.php` — carries `resources` through rotation with the
  same narrowing rules; malformed restrictions abort the refresh.
- New `RequestAccessTokenResourcesEvent.php` (+77) + `RequestEvent::
  ACCESS_TOKEN_RESOURCES_RESOLVING` — lets listeners inspect, modify, or deny
  the resolved resource set before issuance.
- `RequestTypes/AuthorizationRequest.php` (+16) and new
  `RequestTypes/ResourceIndicatorAwareInterface.php` (+34) — carry the requested
  resources through the authorization flow.
- `ResponseTypes/BearerTokenResponse.php` (+3) — persists `resources` in the
  refresh-token payload so rotation preserves the binding.

## Why it is needed

Stock League 9.4.1 structurally cannot issue resource-bound tokens:
`AccessTokenTrait::convertToJWT()` hardcodes `aud` to the OAuth **client ID**,
and the `resource` request parameter is accepted but ignored (reproduced with
real PKCE requests; see `docs/oauth-resource-binding-gap.md`). Without this
patch the MCP gate (`McpAccessPolicy::checkResourceAudience()`, default
`require` mode) rejects every token — or the policy must be weakened to
`audit`, accepting unbound tokens. Must be kept together with patch 2
(`simple-oauth-rfc8707.patch`), which is the Simple OAuth side of the same
contract.

## Third-party source

No downloadable patch file exists. Related upstream work (open, unmerged as of
2026-09-16):

- https://github.com/thephpleague/oauth2-server/pull/1503 — original RFC 8707
  PR by vk-io-dev (open; `AudienceRestrictedTokenInterface` naming; auth-code
  only in its first revision). CI green, no maintainer approval yet; a
  maintainer asked about generative-AI use, author confirmed AI-assisted +
  manual changes.
- https://github.com/thephpleague/oauth2-server/pull/1512 — continuation by
  avgeeklucky (open; +1873/−16, 19 files; adds refresh/client-credentials
  handling and a resources event). One community "any update?" comment,
  2026-09-15; no reviews.
- https://github.com/thephpleague/oauth2-server/issues/1504 — motivating
  issue, explicitly citing MCP servers as the urgent need.
- Spec: https://www.rfc-editor.org/info/rfc8707/

## Removal condition

A released `league/oauth2-server` that supports RFC 8707 for the
authorization-code grant **and** refresh rotation, plus a released Simple
OAuth that propagates it into JWTs (patch 2's exit condition) — then drop
both together after `scripts/test-mcp-oauth-flow.sh`, the functional HTTP
suite, and both conformance clients pass unpatched.
