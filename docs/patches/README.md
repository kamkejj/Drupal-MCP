# Composer patches — findings register

Date recorded: 2026-09-16.
Scope: all four entries under `composer.json` → `extra.patches`, applied via
`cweagans/composer-patches` 2.0. Patch files live in `/patches/` at the repo
root; `patches.lock.json` records each with `"provenance": "root"`.

## Verdict up front

**All four patches are locally authored, all four are currently applied
(verified in `vendor/` and `web/modules/contrib/` on 2026-09-16), and all
four are still needed.** There is no third-party patch file to download for
any of them — no upstream release contains the fixes yet. Removing any one
patch either breaks `/mcp` in its default `require` resource-binding mode or
re-opens a privilege/revocation hole. Details per patch:

| # | Patch file | Package (pinned) | Lines | What it does | Still needed? |
|---|------------|------------------|-------|--------------|---------------|
| 1 | `patches/league-oauth2-server-rfc8707.patch` | `league/oauth2-server` 9.4.1 | 925 | RFC 8707 resource indicators for authorization-code and refresh grants | Yes — with #2 |
| 2 | `patches/simple-oauth-rfc8707.patch` | `drupal/simple_oauth` 6.1.1 | 47 | Emits the authorized resources as a JWT `resource` claim | Yes — with #1 |
| 3 | `patches/simple-oauth-rfc7009-jwt-revocation.patch` | `e0ipso/simple_oauth_21` v1.13.0 | 109 | Revocation lookup by stored identifier (`jti` / decrypted payload id) + refresh ownership via payload `client_id` | Yes |
| 4 | `patches/simple-oauth-token-permissions-user-cache-context.patch` | `drupal/simple_oauth` 6.1.1 | 39 | Adds `user` cache context to token permission calculation | Yes |

Patches 1+2 are a pair: neither works alone. The League patch carries
`resource` through authorize → code → token → refresh; the Simple OAuth
patch opts the token entity into the new contract and writes the claim into
the JWT. The module gate `McpAccessPolicy::checkResourceAudience()` in
default `require` mode rejects tokens with no usable resource binding, so
without the pair every token is rejected (self-DoS) unless the policy is
weakened to `audit`.

## Provenance

- All four diffs were written in this repo during Phase 1 (2026-09-14,
  resource binding; see `docs/oauth-resource-binding-gap.md`) and Phase 5
  follow-up (2026-09-15, revocation + cache isolation; see
  `docs/plans/drupal-mcp-server.md`). Patch 1 is *derived from* open upstream
  League PRs but is not a verbatim copy (different interface naming, refresh
  rotation, narrowing rules).
- `patches.lock.json` marks every entry `"provenance": "root"` with a local
  `patches/*.patch` URL — i.e. there is no `url:` pointing at drupal.org,
  GitHub, or any patch server.

## Upstream tracking (what to watch before removing anything)

| Patch | Upstream state 2026-09-16 | Remove when |
|-------|---------------------------|-------------|
| 1 + 2 | League PR #1503 open, continuation PR #1512 open; neither merged nor released. No Simple OAuth integration issue exists yet (follow-up owed). | A released `league/oauth2-server` supports RFC 8707 for auth-code + refresh **and** a released Simple OAuth propagates it into JWTs; then drop both together after the full OAuth + MCP suites pass unpatched. |
| 3 | No upstream issue found; open `simple_oauth_21` issues (#14, #16, #19, #21) are unrelated. | Upstream fixes stored-identifier revocation lookup and refresh ownership, released; then drop after revocation suite passes unpatched. |
| 4 | Related background issues only (#3507450, #3569279, #3572695, #3588717, #3573262); none publishes this fix. | Simple OAuth varies token permission calculation by user (or documents why its contexts are safe); then drop after the kernel isolation test passes unpatched. |

## Maintenance rules

1. Never patch core/contrib in place — temporary fixes stay Composer-managed
   here and independently reviewed (see
   `web/modules/custom/drupal_mcp/docs/dependency-deprecations.md`).
2. On any `league/oauth2-server`, `drupal/simple_oauth`, or
   `e0ipso/simple_oauth_21` minor/major bump: rebase every affected patch,
   confirm it still applies cleanly, and re-run the full OAuth + MCP suites
   (`scripts/test-mcp-oauth-flow.sh`, `scripts/test-mcp-denials.sh`, unit +
   kernel + functional, conformance clients) before accepting the update.
3. Proof procedure for any single patch: remove it, `ddev composer install`,
   and observe the expected failure — `require`-mode `403`s (#1/#2),
   revoked token still accepted (#3), or kernel isolation failure (#4).
4. Keep this folder in sync: if a patch is rebased, record the new base
   version and re-verification date in its file below.

## Per-patch detail

- [league-oauth2-server-rfc8707.md](league-oauth2-server-rfc8707.md)
- [simple-oauth-rfc8707.md](simple-oauth-rfc8707.md)
- [simple-oauth-rfc7009-jwt-revocation.md](simple-oauth-rfc7009-jwt-revocation.md)
- [simple-oauth-token-permissions-user-cache-context.md](simple-oauth-token-permissions-user-cache-context.md)
