# Independent-client conformance

Last verified: 2026-09-15 against `https://drupalmcp.ddev.site/mcp`.

| Client implementation | Package | Protocol revision | Result |
| --- | --- | --- | --- |
| Official TypeScript SDK | `@modelcontextprotocol/sdk@1.30.0` | `2025-11-25` | Passed initialization, initialized notification, all paginated `tools/list` pages, and `tools/call` for `drupal_whoami`. |
| Official Python SDK | `mcp==2.2.0` | `2026-07-28` | Passed modern direct-version transport, all paginated `tools/list` pages, and `tools/call` for `drupal_whoami`. |

Both clients discovered 14 tools for the same ordinary reader token and
received successful structured content from `drupal_whoami`. Mutation tools
(`drupal_term_*`, `drupal_content_*`) are excluded from that count because
mutation families ship disabled; their catalogue and call behaviour is covered
by the kernel and functional suites.

Run the reproducible harness with:

```shell
web/modules/custom/drupal_mcp/tests/conformance/run.sh
```

The harness pins client versions but installs them only in temporary/cache
locations. It performs a real authorization-code plus PKCE grant with the
pre-registered local test client unless `MCP_ACCESS_TOKEN` is supplied.

## Coverage boundary

The independent SDKs own MCP request framing, revision behavior, transport,
pagination, response validation, and tool invocation. The local token helper
owns browser-form automation because the test client is manually
pre-registered. Existing functional and denial suites remain authoritative for
OAuth discovery/challenges, resource binding, refresh, revocation, invalid
tokens, permission changes, and cookie-only rejection.

This is representative evidence, not a client allowlist. Other clients remain
compatible when they support one of the advertised revisions, Streamable HTTP,
trusted HTTPS, bearer authentication, and configurable pre-registered OAuth
credentials. Automatic/dynamic client registration is not asserted by this
conformance run.
