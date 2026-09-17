# Dependency deprecation register

Deprecations emitted by contributed modules are upgrade work, not current MCP
test failures. Keep this register current before Drupal core, Simple OAuth, or
Consumers upgrades.

## Current baseline

Verified on 2026-09-15:

- Drupal `11.4.6`, PHP `8.4.20`
- `drupal/simple_oauth` `6.1.1`
- `drupal/consumers` `1.24.0`
- `e0ipso/simple_oauth_21` `1.13.0`
- `mcp/sdk` `0.8.1`
- Module suite: `23` tests and `298` assertions passed

The verification run with PHPUnit's deprecation display enabled did not emit a
deprecation detail block. Earlier functional runs reported Drupal-forward-
compatibility notices originating in Simple OAuth, Consumers, and related
contributed code. Until an exact notice is captured again, treat this as an
open compatibility risk rather than claiming it is resolved.

## Tracked items

| Area | Status/owner | Evidence and risk | Required action | Exit condition |
| --- | --- | --- | --- | --- |
| Simple OAuth factories and adapters | Open - release maintainer | Installed `6.1.1` contains APIs marked for removal in Simple OAuth `7.0.0`, including scope-provider, authorization/resource-server factory, token collector, normalizer, and entity-adapter surfaces. Local patches also depend on contributed internals; per-patch findings live in docs/patches/. | Re-run the full OAuth and MCP suites against each candidate Simple OAuth minor/major update. Rebase every Composer patch and review service/class substitutions before accepting `7.x`. | No removed API is used by the module or its patches; OAuth grant, refresh, revocation, resource binding, and cache isolation pass. |
| Simple OAuth permission proxy | Open - release maintainer | `TokenAuthUser::hasPermission()` retains a Drupal `10.3` deprecation branch for non-string permission arguments. The current module passes permission names as strings, but upstream callers must remain compatible. | Capture the full stack if this notice reappears; identify whether the caller is Simple OAuth, Consumers, or custom code. | No non-string permission calls occur under the supported Drupal core version. |
| Simple OAuth static-scope metadata | Open - release maintainer | Contrib warns that legacy top-level `granularity`, `permission`, and `role` plugin properties are removed in Simple OAuth `7.0.0`. This deployment uses dynamic role-granularity configuration, but upgrades can activate or inspect other providers. | Audit enabled scope providers and exported scope configuration before `7.x`; retain `granularity_id` and `granularity_configuration`. | Configuration imports and OAuth scope tests pass without legacy metadata warnings. |
| Consumers and related contrib | Needs exact warning - release maintainer | Earlier functional runs attributed forward-compatibility notices to the Consumers dependency chain, but the latest run did not reproduce the exact text. | On the next occurrence, save the complete message and stack here, link the upstream issue, and test the newest compatible Consumers release in an isolated update branch. | Exact notices are either fixed upstream or covered by a documented temporary compatibility decision. |
| Drupal 12/13 readiness | Open - release maintainer | Current green tests on Drupal 11 do not prove compatibility with future core removals. | Add the next supported Drupal core to CI before upgrading production; run deprecation-failing tests and coding standards against the full dependency stack. | Clean install/update/uninstall, OAuth flows, conformance clients, unit/kernel/functional suites, and coding standards pass on the target core. |

## Verification commands

Capture deprecations without turning today's known contributed warnings into
silent success:

```shell
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" \
  SIMPLETEST_BASE_URL="https://drupalmcp.ddev.site" \
  vendor/bin/phpunit -c web/modules/custom/drupal_mcp/phpunit.xml.dist \
  --display-all-issues
```

Before a Drupal 12/13 or contrib-major upgrade:

```shell
ddev composer outdated --direct
ddev composer audit
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  web/modules/custom/drupal_mcp
web/modules/custom/drupal_mcp/tests/conformance/run.sh
```

Record the package version, complete warning, first relevant contributed stack
frame, upstream issue, and disposition. Do not patch core/contrib in place;
temporary fixes must remain Composer-managed and independently reviewed.
