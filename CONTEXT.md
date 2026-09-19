# drupal_mcp — domain glossary

Names for the concepts this module is built from. Use these words in code,
reviews, and tests; when a concept below names a class, the class is the
concept's home. Architecture reviews should treat these as the vocabulary
for finding and discussing seams.

## Protocol surface

- **Tool** — one MCP operation exposed to AI clients, declared as a
  `Mcp\ToolDefinition` (schema + handler + policy metadata). Tools are never
  discovered by scanning; providers declare them explicitly. Handlers take
  the argument bag and the caller explicitly; they never read the ambient
  account proxy.
- **Caller** — the `TokenAuthUser` the endpoint authenticated for this
  request. The ServerFactory binds it into every registered handler, so the
  caller is an explicit parameter end to end; only the controller and the
  core-adapter boundary touch the account proxy.
- **Tool family** — the unit of exposure: a named group of tools toggled by
  one `drupal_mcp.settings` key. `Mcp\ToolFamily` is the single mapping
  between a family and its settings key (read families under
  `families.<id>`, mutation families under `mutation_families.<id>`).
- **Tool provider** — a tagged service (`drupal_mcp.tool_provider`) that
  contributes tool definitions for one area (content, taxonomy, users,
  diagnostics…). Providers are adapters: schema declaration in front,
  behaviour delegated to deeper modules.
- **Tool registry** — `Mcp\ToolRegistry` filters every provider's catalogue
  per request: family enablement, scope + permission capability, extra
  permissions. The SDK only ever receives tools that survived this filter.
- **Operation capability** — read or write. `Mcp\OperationCapability` owns
  the capability's scope key, default scope, resolved scope, and permission.
- **Signed cursor** — an opaque, HMAC-signed continuation token carrying one
  offset bound to one query context (`Mcp\SignedCursor`). Every paginating
  tool shares this single implementation.
- **Page limit** — the configured pagination maximum and the clamping of
  requested page sizes (`Mcp\Limits`). Every paging surface resolves through
  it, including the SDK server's advertised pagination limit.

## Reads

- **Accessible page** — one bounded page of entities after per-entity view
  access filtering (`Entity\AccessibleEntityPager`).
- **Entity projection** — the allowlisted, access-filtered representation of
  one entity (`Entity\EntityProjection`); the single home of field-exposure
  policy.
- **Entity read tools** — the recurring list/get-one read-tool shapes
  (`Entity\EntityReadTools`): providers declare identity, filter arguments
  with explicit condition operators, and projections; it owns schema
  assembly, clamping, cursor-context binding, id-order paging, view-access
  denials, and the response envelopes. The read-side counterpart of the
  mutation executor.

## Mutations

- **Mutation** — one guarded write requested through a mutation tool.
- **Mutator** — the persistence boundary owning the complete policy for one
  entity type: enablement, access, field normalization, validation,
  revisioning, audit (`Mutation\EntityMutatorInterface`; NodeMutator,
  TaxonomyTermMutator). Expected failures throw `Mutation\MutationException`
  subclasses whose messages are client-safe and whose `category` is a stable
  audit classification.
- **Mutation executor** — `Mutation\MutationExecutor`, the shared execution
  envelope: idempotency-key validation, write-capability account check,
  replay-flag stitching, and safe error mapping. Providers delegate here;
  they never re-implement the ladder.
- **Idempotency key** — a client-supplied key (8–128 chars,
  `A-Za-z0-9._~-`) under which one mutation executes at most once per
  caller/consumer/tool; replays return the recorded result with
  `idempotency_replayed: true` (`Idempotency\IdempotencyManager`).

## Diagnostics

- **Approved Drush command** — a server-defined command spec in
  `Diagnostics\ApprovedDrushCommands`; callers pick an ID, never an argv.
- **Database surface** — a curated read-only view with typed columns and its
  own native permission (`Diagnostics\DatabaseSurface`,
  `ApprovedDatabaseSurfaces`).

## Gate

- **Access policy** — `Access\McpAccessPolicy`, the authorization gate every
  `/mcp` request passes: token authentication, capability scope + permission
  pairs, RFC 8707 resource audience.
- **Endpoint allowlist** — the endpoint's Host and Origin policy
  (`Http\EndpointAllowlist`), parsed once for both the request validator
  and the SDK transport middlewares so the gate and CORS/DNS-rebinding
  enforcement can never disagree.
