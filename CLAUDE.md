# elephentity-wpgraphql

Read [README.md](README.md) first, then [docs/PLAN.md](docs/PLAN.md) — the design
record for this layer, carried over from `elephentity`'s own plan when this package
moved out (elephentity#79).

## Split out of `elephentity`'s `packages/wpgraphql`

This repository holds the runtime half only: the type registrar, the resolvers, the
Relay loader, the verifier itself. Everything build-time — the manifest builder,
`TypeMapper`, the `Integration\WpGraphQL` declaration, the `bin/eleph-gen-wpgraphql`
binary — lives in
[`elephentity-codegen-wpgraphql`](https://github.com/hsimah-services/elephentity-codegen-wpgraphql)
instead, mirroring what `elephentity-codegen-php` already did for the PHP target.

That repository's `src/Runtime.php` names this package's classes as **string
constants**, not imports — it has no dependency on `elephentity/wpgraphql` at all, by
design. Renaming or moving a class here does not fail there; it fails in a real
project, at boot, when the exported manifest or verifier names a class that no longer
exists. Regenerating `clog` in `elephentity-examples` is the check that catches it.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
```

`composer ci` must pass before committing. PHPStan runs at **level max** with no
baseline exclusions, scanning both `php-stubs/wordpress-stubs` and
`axepress/wp-graphql-stubs` so real symbols type-check without a live install —
`Relay/EntityLoader.php` extends `WPGraphQL\Data\Loader\AbstractDataLoader`.

## Conventions that are load bearing

- **`WPGraphQLVerifier` implements `Eleph\Runtime\Conformance\Verifier`** — the seam
  from elephentity#58. `eleph check`, in whatever project installs this, discovers it by
  walking the generated tree for `verify.php` and loading whatever class that file
  names; core never imports this package directly.
- **`NodeRuntime` is the package's one static** — bound once at boot so WPGraphQL's
  `graphql_resolve_node_type` filter, which is handed nothing but the object whose type
  it is asking about, can reach the manifest and gateway it needs. Not a design to
  imitate elsewhere in this package; it exists because WPGraphQL's own hook contract
  leaves no other way to answer that filter.

## Before you commit anything that crosses a repository boundary

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or
with the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape.

## Before you commit

- `./tools/php composer ci`
- If you changed anything `elephentity-codegen-wpgraphql` names in its own
  `src/Runtime.php`, open an issue there — a rename here is invisible to its tests.
- Regenerate `clog` in `elephentity-examples` and read the diff if the change is one a
  real project would see.
