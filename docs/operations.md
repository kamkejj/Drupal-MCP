# Drupal MCP operations runbook

This runbook covers read access and the disabled-by-default taxonomy create/update and node create/update releases. Commands are run from the
Drupal project root unless stated otherwise. Replace example hostnames, client
IDs, redirect URIs, and usernames with deployment-specific values.

## Deployment prerequisites

- Drupal and PHP versions must satisfy the locked Composer dependencies. Run
  `composer install` from the reviewed lock file and confirm all approved
  Composer patches apply cleanly before deployment.
- Serve the canonical MCP resource over trusted HTTPS. Configure Drupal
  `trusted_host_patterns`, `drupal_mcp.settings:resource_uri`, and
  `drupal_mcp.settings:allowed_hosts` to the same canonical origin.
- Keep Simple OAuth public and private keys outside the web root, owned by the
  PHP runtime account, and readable only by the required deployment users.
- Enable only the required OAuth modules and `drupal_mcp`. The read scope must
  use a controlled role ceiling; users still need their native Drupal
  permissions and `access mcp read`.
- If Drush diagnostics are enabled, provide `setsid` at `/usr/bin/setsid` or
  `/bin/setsid` and PHP POSIX functions. The diagnostic family fails closed
  without both.
- If database diagnostics are enabled, provision the separate read-only
  database principal and curated views before enabling the family. Do not use
  Drupal's application database account.
- Run Drupal cron so expired OAuth tokens and normal operational state are
  maintained. Back up configuration, OAuth consumer definitions, and key
  material through the deployment's secret-management process.

## OAuth client registration

The first release supports manual pre-registration. Do not expose unaudited
dynamic client registration merely to make onboarding automatic.

Create one OAuth consumer per client application and environment:

- Use a stable, non-secret client ID.
- Register every redirect URI exactly; do not use wildcards.
- Use authorization code with PKCE S256 and refresh tokens.
- Prefer a public client for native or local applications that cannot protect a
  client secret. Confidential clients must store their secret outside source
  control and browser-delivered configuration.
- Allow `mcp:read` for readers. Allow `mcp:write` only for clients and users approved for mutation; scope possession never replaces `access mcp write` or native Drupal access.
- Set bounded access- and refresh-token lifetimes appropriate to the deployment.

Registration is an onboarding action, not authorization. The user represented
by the token still needs `access mcp read`, applicable native Drupal
permissions, and an active account. Client credentials alone never grant MCP
access.

Known registration limitations:

- Clients must support configurable pre-registered OAuth credentials or a
  compatible manual OAuth flow.
- Redirect URI changes require an administrator to update the consumer before
  the client can authenticate.
- Stdio-only clients need a separately reviewed Streamable HTTP adapter.
- Cloud clients need a publicly reachable trusted HTTPS endpoint and cannot
  reach a developer-only DDEV hostname.
- Automatic client registration is not part of the first-release support
  contract, even if a contributed registration submodule is installed locally.

## Sanitized client setup

Configure clients with non-secret values only:

```json
{
  "server_url": "https://drupal.example/mcp",
  "transport": "streamable-http",
  "oauth": {
    "client_id": "registered-client-id",
    "redirect_uri": "http://127.0.0.1:PORT/oauth/callback",
    "scope": "mcp:read"
    "pkce": "S256"
  }
}
```

Never place access tokens, refresh tokens, passwords, private keys, or
confidential-client secrets in a checked-in client configuration. A client must
discover OAuth metadata, open the browser login/consent flow, and send the
resulting bearer token only in the `Authorization` header.

## Taxonomy and node write enablement

Keep `mutation_families.taxonomy`, `writable_vocabularies`,
`mutation_families.node`, `writable_node_bundles`, and
`destructive_mutations.taxonomy_terms` at their default-off values until a
reviewed deployment explicitly needs them. For Tags mutations, grant a dedicated
role `access mcp write` plus the required native taxonomy and text format
permissions, provision the role-bounded `mcp:write` OAuth scope, enable
`mutation_families.taxonomy`, and add `tags` to `writable_vocabularies`. Enable
`destructive_mutations.taxonomy_terms` separately only when term deletion is
required. Do not grant read access implicitly: clients needing both capabilities
must request both scopes and the user must hold both permissions.

Idempotency records contain a keyed caller identity digest, payload digest, and
bounded result for `idempotency_ttl` seconds. Monitor mutation audit outcomes
and unexpected failures; arguments and idempotency keys are never logged.
Term deletion remains default-off, is advertised only when explicitly enabled,
and requires revision and name confirmation; referenced terms are not deleted.

Node writes follow the same model: grant a dedicated role `access mcp write`
plus the required native node and text-format permissions, enable
`mutation_families.node`, and list writable bundles under
`writable_node_bundles` (always a subset of the read-exposed `node_bundles`).
`drupal_content_get` exposes the `revision_id` clients must pass as
`expected_revision_id` to `drupal_content_update`; updates save new revisions
and both operations require idempotency keys. Node deletion is not exposed.

## Release verification

Run the module suite and the independent-client conformance harness:

```shell
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" \
  SIMPLETEST_BASE_URL="https://drupalmcp.ddev.site" \
  vendor/bin/phpunit -c web/modules/custom/drupal_mcp/phpunit.xml.dist

web/modules/custom/drupal_mcp/tests/conformance/run.sh
```

The conformance harness obtains a short-lived test token unless
`MCP_ACCESS_TOKEN` is already set. It runs the pinned TypeScript SDK against
`2025-11-25` and the pinned Python SDK against `2026-07-28`, lists every tool
page, and calls `drupal_whoami`. Test credentials belong in
`secrets/mcp-test-credentials.txt` or `MCP_PASSWORD`; never commit them.
The harness uses the operating system trust store when Node supports it. Set
`MCP_CA_FILE` to a readable PEM CA bundle when the deployment CA is not in the
system trust store. Never disable TLS verification for a conformance run.

After deployment, also verify:

1. Anonymous, invalid-token, and cookie-only requests return JSON `401`
   responses with the expected Bearer challenge.
2. A reader can list permitted tools and call `drupal_whoami`.
3. A token for the wrong resource, a revoked token, and a blocked user fail.
4. Responses include `Cache-Control: private, no-store`.
5. Privileged Drush and database tools are absent for ordinary readers.

## Monitoring

Collect Drupal logs for the `drupal_mcp` channel and the OAuth provider. Alert
on trends, not bearer-token contents:

- sustained `401` or `403` increases, separated from normal expired-token
  traffic;
- `429` responses indicating per-user or per-consumer flood limits;
- MCP dispatch `500` responses;
- repeated OAuth authorization, refresh, or revocation failures;
- Drush/database diagnostic failures, timeouts, or output-limit terminations;
- certificate expiry, key-file permission failures, cron failures, and low disk
  space affecting Drupal state or logs.

Retain user ID, consumer ID, MCP method, status, duration, timestamp, and a
request/correlation ID where available. Never log bearer headers, authorization
codes, refresh tokens, client secrets, passwords, full tool arguments, command
output, or database rows.

## Rotation

### Test or user credentials

Reset the Drupal password, revoke outstanding grants/tokens for the affected
user, and delete old entries from `secrets/mcp-test-credentials.txt`. Re-run the
setup script only when new local fixtures are required.

### OAuth client credentials

Public PKCE clients have no client secret. To rotate their identity, register a
new consumer/client ID and exact redirects, migrate the client, revoke tokens
for the old consumer, then disable or delete the old consumer.

For a confidential client, create a new secret through the deployment's
approved administration path, update the secret store and client, verify a new
authorization flow, revoke old tokens, and retire the previous secret. Never
send a secret through chat, tickets, logs, or command history.

### Signing keys

Signing-key rotation invalidates existing access to avoid an ambiguous overlap
between key generations. Schedule a reauthentication window and keep the old
private key active until its tokens have been removed:

1. Back up the current key files through the secret manager.
2. Revoke or purge all access and refresh tokens while the old private key can
   still decrypt and identify them.
3. Generate a replacement pair in a separate directory outside the web root
   with `drush simple-oauth:generate-keys /absolute/staging/key-directory`.
4. Apply restrictive ownership and permissions, atomically replace the active
   pair (or update both configured key paths together), and clear Drupal caches.
5. Require clients to authenticate again, then verify discovery, authorization
   code plus PKCE, refresh, `/mcp`, and
   revocation before ending the maintenance window.

## Recovery

- **Compromised token:** revoke it immediately, confirm `/mcp` returns `401`,
  review scoped audit events, and rotate the user's credentials if the login
  session may also be compromised.
- **Compromised client:** disable its consumer, revoke its tokens, preserve
  audit evidence, register a replacement client ID, and reauthorize users.
- **Compromised signing key:** remove external access to `/mcp`, rotate the key
  pair, revoke all tokens, verify the OAuth flow, and then restore access.
- **OAuth outage:** keep `/mcp` fail-closed. Restore key access, database
  connectivity, configuration, and contributed modules; never enable anonymous
  or static-token fallback.
- **MCP regression:** disable the affected tool family or the module, preserve
  Drupal/OAuth data, deploy the last verified artifact, clear caches, and rerun
  denial plus independent-client checks.
- **Database diagnostic fault:** disable only the database family, repair the
  read-only principal/views, and rerun `scripts/provision-db-readonly.sh` before
  re-enabling it.

Uninstalling `drupal_mcp` must not delete editorial content, users, OAuth
clients, or tokens belonging to other integrations. Reinstalling removes role
permission grants as part of normal Drupal behavior, so reapply reviewed role
configuration before restoring service.
