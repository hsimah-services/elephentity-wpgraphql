# elephentity-wpgraphql

Exposes [Elephentity](https://github.com/hsimah-services/elephentity) entities and
mutators to [WPGraphQL](https://www.wpgraphql.com/) from a compiled manifest: object
types, enums, Relay connections, mutations, and the global-id/node-resolution plumbing
WPGraphQL expects.

```bash
composer require elephentity/wpgraphql
```

You will also want the build-time builder that produces the manifest this package
loads: `composer require --dev elephentity/codegen-wpgraphql`.

## What this is

A runtime package: the type and mutation registrars, the Relay loader and global-id
codec, the connection resolver, and the conformance verifier. It implements
[`Eleph\Runtime\Conformance\Verifier`](https://github.com/hsimah-services/elephentity-runtime/blob/main/src/Conformance/Verifier.php)
and requires nothing but `elephentity/runtime`.

`Plugin` is the whole layer as one object — nothing in Elephentity depends on it. A
project that speaks no GraphQL never enables the integration, never generates a
manifest, and never loads a class from this package.

## What this is not

Not a code generator. The compiler that turns a spec into the GraphQL manifest this
package loads lives in
[`elephentity-codegen-wpgraphql`](https://github.com/hsimah-services/elephentity-codegen-wpgraphql)
— a separate, build-time-only repository that depends on nothing of Elephentity's, so
it never ships to production. This package and that one are held in step by a version
gate on the wire format, not a shared classpath: see
[`.llms/cross-repo.md`](.llms/cross-repo.md).

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
```

`composer ci` must pass before committing. PHPStan runs at **level max** with no
baseline exclusions.

## Before you commit anything that crosses a repository boundary

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or
with the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape.

**Renaming a class here is invisible to every test in this repository or in
`elephentity-codegen-wpgraphql`.** The builder emits the class name as a string, not an
import — see `Runtime.php` there. It only fails in a real project, at boot, after
generating. Regenerating `clog` in
[`elephentity-examples`](https://github.com/hsimah-services/elephentity-examples) is
what catches it.
