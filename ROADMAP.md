# Roadmap to v1.0.0

Current release: **v0.1.1**. Being on `0.x` is the whole point of what follows — it is the only window in which the public API can still change without a major version.

So this roadmap is not ordered by how appealing a feature is. It is ordered by **what has to be decided before the API freezes**. A feature is cheap to add after 1.0 if it only adds; it is impossible to add after 1.0 if it changes something a host app has already written against.

No dates. The order is the commitment.

## What 1.0.0 freezes

Everything a host app writes code against:

- `Contracts\FileExplorerAuthorizer` — including the **key set** its `abilities()` returns
- `Contracts\FileExplorerRootResolver`, and the optional `ProvidesMultipleRoots` / `ResolvesExistingRoot`
- The three shipped resolvers, and `FilamentFileExplorerPlugin`'s fluent setters
- `Models\Concerns\HasFileExplorer`, and `Models\Folder` as the tree model
- The abstract pages (`Pages\FileExplorerPage`, `Pages\FileExplorerFilesPage`) and the `stubs/` generated from them
- The config keys, and the `StandaloneSettings` precedence rules
- The media route names, and their scope-key URL segment
- The DOM contract the JS is built on: `data-fe-items`, `data-fe-type`, `data-id`

## Before 1.0 — decisions that cannot be deferred

All four are done: **domain events**, **the ability set**, **the folder model** and **the selection**. Nothing structural stands between here and 1.0 — what is left below is features and polish.

### ~~An ability set that can grow~~ — done

[Support/Abilities.php](src/Support/Abilities.php) is now the only reader of `abilities()`. `ORIGINAL` is the published set, where silence is still a denial; `DERIVED` maps an ability added since to the one it inherits from when the authorizer says nothing, so a new capability no longer denies itself for every implementation already written. `share` is declared ahead of the feature that will read it — the declaration has to exist before the key is used, which is the whole point.

Nothing defaults to allowed, an authorizer that answers a derived key overrides the inheritance, and keys an authorizer volunteers of its own are kept.

It also stopped asking the authorizer on every read. `ability()` runs dozens of times per render and an authorizer consulting policies or the database was being asked each time; the resolver is bound `scoped` and answers once per scope per request.

The signed share links can now be built without smuggling them onto an existing ability.

### ~~A swappable folder model~~ — done

`folders.model` names the class, and [Support/FolderModel.php](src/Support/FolderModel.php) is the only place it is built. Seventy-two static usages across ten files became resolver calls, and the relations moved from `self::class` to `static::class` so a host app does not get its own class from a query and the package's from a relation.

The sharp edge was the morph class. `getMorphClass()` now answers for the base model rather than `static`, so a swap does not orphan a library already uploaded: media rows keep the same `model_type`, which is the value the containment guard checks. Had it followed the subclass, every existing row would have started failing that check — silently, and only for the apps that took the new feature up.

A test asserts the static forms never come back, since they creep in the moment someone adds a query without thinking about it.

### ~~Selection state per component, not per page~~ — done

`createFeSelection()` is a factory and each component holds one as `sel` in its own `x-data`, with a `$wire` of its own. The views reach it as `sel` rather than `$store.feSel` — 72 call sites — because children inherit the Alpine scope, so nothing is addressed page-wide any more.

The `feDrag` store stays shared, which is right: a user drags in one explorer at a time. What changed is that it no longer reaches for a selection of its own — `pointerDown` is handed the one the drag started in.

Seven tests in the `node:vm` harness cover it, which needed the harness to be able to put two explorers in one realm. Reintroducing a shared selection fails all seven.

### ~~Domain events~~ — done

Fourteen events, dispatched from both mutation entry points, all implementing the `Contracts\FileExplorerEvent` marker interface so one listener can see everything. See [the README](README.md#events) for the payloads.

An interface rather than a shared base class because Laravel's dispatcher resolves listeners for interfaces but not for parent classes — the difference between an audit trail that works with one listener and one that has to enumerate fourteen.

Closing this also fixed a data-loss bug it uncovered: the files table's delete called `$record->delete()` directly, destroying the file even with the trash on, so the same button meant two different things depending on the page it was pressed from. It now routes through `Support\Trash` like the explorer's own delete.

## Features planned before 1.0

### Signed, expiring share links

The most asked-for capability of any file manager, and the security model is already shaped for it: the media route takes the scope key as a URL segment and resolves the root through `ScopeRoots`, so a sibling route without `auth` and with a signed, expiring URL fits the existing containment check rather than working around it.

Unblocked: the `share` ability is declared and resolves for every authorizer, so what is left is the route, the expiry and the UI.

### The column view

The package calls itself Finder-style and ships grid, list, table and details. The column browser, which is the Finder's signature view, is missing.

The ground is prepared: the DOM contract is documented as the extension point for a new mode, and `orderedItems()` already groups by `offsetTop` without knowing the column count.

### Sort by size, and filter by kind

`FolderListing` already sorts and windows in SQL and `media.size` is a column, so the sort itself is small. What needs deciding is the folders: they fill the window first and have no size of their own. Either they stay grouped ahead of the files, or a recursive weight is computed — which is expensive and would have to be cached.

## After 1.0 — additive, and none of it blocking

- **File versioning.** The `replace` conflict policy destroys what was there. Keeping the last N versions would follow the pattern the trash already proved: move aside rather than destroy.
- **Chunked and direct-to-S3 uploads.** `upload.max_size_kb` defaults to 50 MB, but the real ceiling is the host's `post_max_size`. This is what unblocks video and large media.
- **Tags and descriptions**, searchable — in `custom_properties` or a table of their own.
- **Touch support.** The drag geometry and the marquee assume a mouse. A functional gap rather than a refinement as soon as a panel is opened on a tablet.
- **A storage widget.** The quota already renders in the sidebar; exposing it as a per-scope dashboard widget is nearly free.
- **PDF and video thumbnails, opt-in.** Deliberately absent today because they need Imagick or ffmpeg on the host and a failing generator costs more than the icon already drawn. Worth offering behind an explicit flag — never by default.

## Out of scope

- **RTL.** The layout, the drag geometry and the marquee all assume `dir="ltr"`, which is why the finder is rendered that way on purpose. This is not a matter of adding `rtl:` variants — those can never match — but of making the whole layout direction-aware. Large, and narrow in benefit.
- **Full-text search inside documents.** That belongs to Scout or Meilisearch in the host application, not to a file explorer.

## Known gaps to close along the way

- With the trash **off**, a folder purged by another session while a user stands in it leaves Livewire unable to restore the model at all, and the component fails until the page is reloaded. Soft-deleted folders take the fallback correctly.
- `pint` reports pre-existing style drift in `config/` and `resources/lang/` (strict types, import order). Worth clearing in one pass rather than file by file.
- `larastan` is a dev dependency with no `phpstan.neon`. Static analysis is not wired up, so nothing enforces the type annotations the code already carries.
