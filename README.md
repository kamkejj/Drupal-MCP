# Drupal MCP

[![CI](https://github.com/kamkejj/Drupal-MCP/actions/workflows/ci.yml/badge.svg)](https://github.com/kamkejj/Drupal-MCP/actions/workflows/ci.yml)

This module exposes Drupal through a bounded Model Context Protocol (MCP)
server at `https://drupalmcp.ddev.site/mcp`. It lets authenticated MCP clients
inspect selected site data and structure, run explicitly approved diagnostics,
and perform narrowly allowlisted taxonomy mutations. Every request requires a
Simple OAuth bearer token associated with an active Drupal user; OAuth scopes
limit the token while Drupal permissions and entity access remain authoritative.

## Capabilities

The module provides these MCP tool families:

- Site identity and current-user information.
- Entity-type, bundle, content-type, field, and role schema inspection.
- Paginated reads of allowlisted published content and taxonomy terms, with
  Drupal entity and field access checks applied to every result.
- Bounded inspection of blocks, menus, URL aliases, files, and user profiles.
- Disabled-by-default Drush diagnostics limited to approved, typed,
  non-destructive commands.
- Disabled-by-default database diagnostics against curated views through a
  separately provisioned read-only database principal.
- Disabled-by-default taxonomy term creation, update, and deletion for
  explicitly writable vocabularies. Mutations enforce native Drupal access,
  revision preconditions, field validation, and idempotency where applicable.

The server uses Streamable HTTP, OAuth authorization-code flow with PKCE,
RFC 8707 resource binding, paginated tool discovery, signed cursors, bounded
request/output sizes, rate limiting, and server-side MCP sessions. Tool families
and exposed bundles, vocabularies, commands, and database surfaces are all
configured explicitly and fail closed.

## OAuth capabilities

`mcp:read` exposes only tools for which the authenticated user also has `access
mcp read` and every native Drupal permission required by the tool. `mcp:write`
exposes only enabled mutation tools when the user also has `access mcp write`
and the required native entity, field, and text-format access. Scope possession
never grants a Drupal permission or bypasses entity access.

The scopes are independent capability ceilings: `mcp:write` does not imply
`mcp:read`, and `mcp:read` does not permit mutations. Request both scopes when a
client needs both catalogues. Taxonomy mutations remain unavailable until the
`taxonomy` mutation family and at least one writable vocabulary are explicitly
enabled; destructive deletion has a separate opt-in switch.

Run the commands below from the Drupal project root.

## Composer patches

The current release requires the security and protocol patches listed in
[`patches.json`](patches.json). A root Drupal project must use
`cweagans/composer-patches` and point its `extra.patches-file` setting at the
module's patch manifest. The CI workflow installs the module into a clean
Drupal project and fails if any required patch no longer applies.

## Test prerequisites

Start DDEV and confirm that the MCP and OAuth modules are enabled:

```shell
ddev start
ddev drush pm:list --type=module --status=enabled --format=list \
  | grep -E 'drupal_mcp|simple_oauth'
```

If the local OAuth test users, roles, scope, and PKCE client have not been
created, run:

```shell
ddev drush php:script scripts/mcp-oauth-setup.php
```

The setup script writes generated test-user passwords to
`secrets/mcp-test-credentials.txt`. The file is outside the web root and must
not be committed. Rotate or delete these credentials after testing.

## Automated tests

Run the module's PHPUnit suite (unit, kernel, and functional tests; kernel and
functional tests need the test database and base URL):

```shell
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" \
  SIMPLETEST_BASE_URL="https://drupalmcp.ddev.site" \
  vendor/bin/phpunit -c web/modules/custom/drupal_mcp/phpunit.xml.dist
```

Run the architecture and security checks against the bootstrapped Drupal site:

```shell
ddev drush php:script scripts/test-mcp-architecture.php
```

Test the complete authorization-code and PKCE flow as a non-administrator MCP
reader:

```shell
scripts/test-mcp-oauth-flow.sh mcp_reader_user
```

This covers login, consent, token exchange, RFC 8707 resource binding, MCP
initialization, `drupal_whoami`, discovery, compatibility sessions, refresh
tokens, and invalid-token rejection.

The functional suite additionally proves that access- and refresh-token
revocation (RFC 7009) takes effect immediately on `/mcp`; revocation of
encrypted refresh tokens currently relies on the Composer-managed contrib
patch `patches/simple-oauth-rfc7009-jwt-revocation.patch`.

Exercise the read-only tool surface as both a reader and an administrator:

```shell
scripts/test-mcp-tools.sh mcp_reader_user
scripts/test-mcp-tools.sh mcp_admin_user
```

Running both accounts verifies that Drupal permissions control which tools and
records are visible.

Run the authentication and authorization denial-path tests:

```shell
scripts/test-mcp-denials.sh
```

These checks cover missing and invalid bearer tokens, cookie-only requests,
userless client-credentials tokens, missing OAuth scope, missing Drupal
permission, and revoked access/refresh tokens.

## Database diagnostics

Provision and verify the restricted database-inspection principal when testing
the database tools:

```shell
scripts/provision-db-readonly.sh
```

The provisioning check proves that the principal can read only the curated
inspection schema and cannot read the normal Drupal schema or perform writes.
The user-account surface additionally requires `administer users`; the taxonomy
surface requires `administer taxonomy`. Node data is intentionally unavailable
through database diagnostics because SQL cannot safely reproduce Drupal node
access grants; use the entity content tools instead.

## Drush diagnostics

Drush diagnostics require a Unix deployment with `/usr/bin/setsid` or
`/bin/setsid` and PHP's POSIX process functions. Each command runs in an
isolated process group so output-limit, timeout, and integration failures
terminate the command and all descendants. Diagnostics fail closed when these
process-control capabilities are unavailable.

## Security semantics worth knowing

- Field-level structure (definitions, types, labels) is exposed only through
  `drupal_fields_list` and `drupal_content_type_get`, which require the
  native `administer content types` permission. `drupal_schema_get` returns
  entity-type and bundle structure only.
- `drupal_file_get` follows Drupal core's file access semantics: metadata of
  files in the public filesystem is visible to accounts with `access
  content`, exactly as rendered `<img>` tags would reveal. Private-scheme
  files still require core's download permission.
- Pagination cursors are HMAC-signed with the site hash salt and capped;
  they cannot be forged by clients.
- Drush `config:get` is limited to server-defined projections. The initial
  `system.site` projection returns only the site name, slogan, front page, and
  default language; email addresses, UUIDs, config hashes, and other properties
  are removed even when the configuration object is enabled.
- Uninstalling the module removes its permission grants from roles (standard
  Drupal behavior). Reinstalling does not restore them; re-run
  `scripts/mcp-oauth-setup.php` and re-enable the diagnostics families in
  settings afterwards.

## Manual MCP client testing

Use the following development settings with MCP Inspector or another client
that supports Streamable HTTP and OAuth authorization-code flow with PKCE:

- Server URL: `https://drupalmcp.ddev.site/mcp`
- OAuth client ID: `mcp-inspector`
- Redirect URI: `http://127.0.0.1:6274/oauth/callback`
- OAuth scope: `mcp:read` for read tools, `mcp:write` for mutations, or both
  scopes for both capabilities
- Reader username: `mcp_reader_user`
- Writer username: `mcp_writer_user`
- Administrator username: `mcp_admin_user`
- Password file: `secrets/mcp-test-credentials.txt`

Anonymous MCP access is intentionally disabled. Opening or calling `/mcp`
without a valid bearer token should return HTTP `401 Unauthorized`.

## Independent-client conformance

Run both advertised protocol revisions through pinned, independent official MCP
clients:

```shell
web/modules/custom/drupal_mcp/tests/conformance/run.sh
```

The harness uses TypeScript SDK `1.30.0` for handshake revision `2025-11-25`
and Python SDK `2.2.0` for modern revision `2026-07-28`. Each client traverses
the paginated tool catalogue and calls `drupal_whoami`. Set `MCP_ACCESS_TOKEN`
to supply an existing token; otherwise the harness obtains a short-lived token
with the local PKCE test client and credentials file. Dependencies are installed
into a temporary directory and do not modify this module or the Drupal project.
The default obtains a read token. Set `MCP_TOKEN_CAPABILITY=write` to obtain the
write fixture token, or set `MCP_ACCESS_TOKEN` directly. Write-only tokens do
not inherit read catalogue access.

## Taxonomy mutations

Taxonomy mutations are configured at **Administration > Configuration > Web
services > Drupal MCP settings** (`/admin/config/services/drupal-mcp`) by an
account with `administer site configuration`. Under **Mutations (restricted)**:

1. Enable **Enable taxonomy term mutations**.
2. Select **Tags (tags)** under **Writable vocabularies**. This release supports
   only the `tags` vocabulary for writes.
3. Leave **Enable destructive taxonomy term deletion** disabled unless deletion
   is explicitly required.
4. Set the idempotency retention period; the default is 86,400 seconds.
5. Save the configuration and rebuild caches.

The equivalent DDEV/Drush configuration for create and update is:

```shell
ddev drush config:set drupal_mcp.settings mutation_families.taxonomy true -y
ddev drush config:set drupal_mcp.settings writable_vocabularies '["tags"]' --input-format=json -y
ddev drush config:set drupal_mcp.settings idempotency_ttl 86400 -y
ddev drush cr
```

Enable deletion separately only when needed:

```shell
ddev drush config:set drupal_mcp.settings destructive_mutations.taxonomy_terms true -y
ddev drush cr
```

Verify the effective settings with:

```shell
ddev drush config:get drupal_mcp.settings mutation_families
ddev drush config:get drupal_mcp.settings writable_vocabularies
ddev drush config:get drupal_mcp.settings destructive_mutations
```

`drupal_term_create` and `drupal_term_update` are exposed only after the
taxonomy mutation family is enabled and `tags` is writable.
`drupal_term_delete` is advertised only when
`destructive_mutations.taxonomy_terms` is also enabled. Every operation still
requires an `mcp:write` token, `access mcp write`, and applicable native Drupal
entity, field, and text-format access; configuration never bypasses those
checks.

Updates require the current `expected_revision_id`; create and update also
require an idempotency key. Delete requires exact revision and term-name
confirmation and refuses to delete referenced terms.

See [docs/conformance.md](docs/conformance.md) for the evidence matrix and
coverage boundary. [docs/operations.md](docs/operations.md) covers deployment,
registration, rotation, monitoring, and recovery procedures. Track
Drupal-forward dependency work in
[docs/dependency-deprecations.md](docs/dependency-deprecations.md).
