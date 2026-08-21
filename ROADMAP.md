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

One down: **domain events** shipped. The three that remain all change something a host app has written against, which is why they cannot wait.

### An ability set that can grow

`abilities()` returns a fixed array of eleven keys. Any new key is a **silent denial** for every authorizer a host app has already written: the package reads a key the implementation never returned, gets nothing, and refuses the action. This is already documented as the reason trash restore and purge reuse `delete` / `deleteFolder` rather than asking for abilities of their own.

Left as it is, that constraint becomes permanent at 1.0, and every future capability — sharing first among them — has to be smuggled onto an existing ability.

The decision to make is how an unknown ability resolves: a declared fallback per new key, a base-ability mapping, or capability negotiation against the implementation. Whichever it is, it has to ship **before** the freeze, not after.

### A swappable folder model

`Folder::` is referenced 57 times across 8 files, and no config key points at the class. A host app cannot substitute its own model — to add columns, a global scope, or a different table strategy — without forking.

Every comparable Filament and Spatie package lets the model be swapped. Adding that later means changing the type of everything the package hands back, so it is a 1.0 decision.

### Selection state per component, not per page

The Alpine `feSel` store and its `feWireRef` are global. Two explorers on one page — which the `FileExplorerPicker` in a modal makes an ordinary situation — share one selection, and `setSelection` reports to whichever component initialised last.

This is the last global left after the `id` sweep, and it is the one no PHP test can reach. The fix is to move the selection into the component or key the store by scope; both change the JS contract, so it belongs before the freeze.

### ~~Domain events~~ — done

Fourteen events, dispatched from both mutation entry points, all implementing the `Contracts\FileExplorerEvent` marker interface so one listener can see everything. See [the README](README.md#events) for the payloads.

An interface rather than a shared base class because Laravel's dispatcher resolves listeners for interfaces but not for parent classes — the difference between an audit trail that works with one listener and one that has to enumerate fourteen.

Closing this also fixed a data-loss bug it uncovered: the files table's delete called `$record->delete()` directly, destroying the file even with the trash on, so the same button meant two different things depending on the page it was pressed from. It now routes through `Support\Trash` like the explorer's own delete.

## Features planned before 1.0

### Signed, expiring share links

The most asked-for capability of any file manager, and the security model is already shaped for it: the media route takes the scope key as a URL segment and resolves the root through `ScopeRoots`, so a sibling route without `auth` and with a signed, expiring URL fits the existing containment check rather than working around it.

Gated on the ability decision above — this is the feature that makes it urgent.

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
