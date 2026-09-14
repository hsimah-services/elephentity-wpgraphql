# elephentity-wpgraphql: design notes

Carried over from `elephentity`'s `docs/PLAN.md` §13 when
[elephentity#79](https://github.com/hsimah-services/elephentity/issues/79)
moved the WPGraphQL runtime to this repository — the reasoning is the artifact,
not the code, so it moved with the code it explains. Section numbering is as
it stood in the monorepo; nothing here has been re-derived.

## 13. Plugin layer: WPGraphQL

Registration is driven by a **compiled manifest generated at build time** and read at
runtime — rather than generating resolver PHP (which can drift) or walking the IR
reflectively on every request (runtime cost, no static analysis).

Convention: spec field `title` → `getTitle()` on the read object → GraphQL field
`title`.

**Registration is a loop, not generated code.** WPGraphQL takes a closure for every
resolver, so the whole surface is a walk over the manifest. A generated copy per entity
would be one more tree to keep in step with the spec, and would buy nothing.

**`Plugin` is the whole layer as one object.** Nothing in Elephentity depends on it: a
project that speaks no GraphQL never enables the integration, never generates a
manifest, and never loads the class.

**Resolvers go through `EntityGateway`** — entities addressed by *name*. Everything else
in the framework is exactly typed, and a protocol layer cannot be: a resolver is handed
the string "Item" and an array of arguments with no compile-time way to reach
`ItemFinder`. So the gateway is the one place type safety is given up on purpose, kept
as small as the protocol layers need, with everything behind it still typed.

**Paging is offset-based**, and worth being honest about: insert or remove rows between
two pages and a reader can see one twice or miss one. Keyset paging avoids that but
needs the ordering columns in the cursor and a stable total order the spec does not yet
make anyone declare. The cursor is opaque, so replacing it later changes nothing above
the adaptor.

The compiler asks for one row more than the caller wanted — its presence is how
`hasNextPage` is answered without a second query, and the adaptor drops it before the
page is returned.

### Every type is a Node

Every exposed entity implements WPGraphQL's `Node` interface, and the price of saying so
honestly is that `id` changed shape.

**`id` is global, `databaseId` is the row.** A row number identifies a row within its
table and nothing beyond it, so a client with a normalised cache files two entities of
the same row number in one place and then serves the wrong one. `id` is
`base64('eleph:<Type>:<row>')` — opaque to the client, unique across the schema — and
`databaseId` keeps the raw value for anything addressing the row outside GraphQL. Both
stay `ID` rather than `Int`, for the same reason the id always was: a later move to
UUIDv7 should be invisible.

**Every id-shaped input decodes leniently.** A root field's `id`, a mutation's `id`, an
edge written by naming its target, and a spec argument typed `id` all accept either
form: decoding requires the `eleph:` prefix after base64, and anything that does not
match is passed through exactly as it arrived. So a hand-written query, an older client,
and an `id` argument that means something else all keep working, and nothing has to know
which case it is in.

**The loader key is one constant, not the type name.** WPGraphQL splits a global id at
its first colon and looks the left half up as a data loader. It builds loaders itself
from a class name — `new $class($context)` — so a shared class cannot be told which type
it was registered for, and a generated loader class per entity would be one more tree to
keep in step with the spec. So one loader is registered under `eleph`, and the type name
is the *second* segment, which that loader reads.

**`NodeRuntime` is the package's only static, and it is a shim.** WPGraphQL offers no
seam for the manifest and the gateway to reach either the loader it constructs or
`graphql_resolve_node_type`, which is handed nothing but the object whose type it is
asking about. So they are bound once at boot and read back from there. A read model
carries no type name, so the loader — the one place that knows both — records the
pairing as it resolves, which is complete because `node` is the only field in the
manifest whose value has to be identified after the fact.
