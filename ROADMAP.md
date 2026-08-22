# Roadmap to v1.0.0

Current release: **v0.5.0**, which emptied the pre-1.0 list entirely — every decision below is settled and every feature shipped. The four decisions that could not wait closed in v0.2.0. Being on `0.x` is the whole point of what follows — it is the only window in which the public API can still change without a major version.

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

All done. What follows is kept for the record of why each was shaped the way it was.

### ~~Expiring share links~~ — done

Stored tokens rather than signed URLs, because revoking is the half of sharing that matters. See [the README](README.md#share-links).

The interesting part was that a public request has no authenticated user, so the ability check and the containment check cannot both run at request time. They are split in time: the ability is checked when the link is made and the decision is recorded; containment runs on every request against the root stored on the row. That is what makes a link die on its own when the file is moved out of scope, trashed or deleted — no bookkeeping, and no way to keep serving something the explorer would no longer show.

Two extractions came first, so no security rule ended up with two copies: `ServesMediaFiles` for the disk-and-conversion response logic, `Support\MediaScope` for the three-condition containment check that had been written out in both the controller and the component.

### ~~The column view~~ — done

One pane per level of the path, the browsed folder on the right, the trail marked behind it. See [the README](README.md#views).

The DOM contract turned out to be honoured best by omission: only the last pane carries `data-fe-type` / `data-id`, so the selection, the keyboard and the marquee keep operating on the folder being browsed and no new keyboard semantics had to be invented for panes.

It also forced a mislabel into the open. `table` had been labelled *Columns*, which is the Finder's name for this browser and not what `table` renders; `table` is now *Table*.

### ~~Sort by size~~ — done

`FolderListing::SORTS` is now the single list of what the listing can be ordered by, and `size` is in it. The folders question answered itself: they already fell back to their name for a sort by *kind*, which they have no more of than they have a size, so `size` does the same and stays consistent rather than inventing a special case. A recursive subtree weight would be the only honest folder size and costs a query per folder or a denormalised column to keep in step with every mutation — not worth it for an ordering.

Ties break on the name before the primary key, since an empty file or several copies of one are common and the order should stay readable, not merely deterministic.

### ~~Filter by kind~~ — done

[Support/FileKinds.php](src/Support/FileKinds.php) owns both the list the menu offers and the predicate that matches it, so one cannot offer a kind the other ignores. Applied in `filesQuery()` before the window and before the counts, which is what makes "5 of 12" describe what the filter left.

Matched on the mime type rather than the extension, for the same reason the sort by type is: extracting an extension is not portable across drivers, and the filter must run in SQL. Only the files are narrowed — a folder has no kind, and hiding folders would filter the user into a room with no doors.

Not remembered across mounts, unlike the view mode and the sort. Those are how someone likes to look at a library; a filter is what they are looking for right now.

### ~~Tags and descriptions~~ — done

Tables of their own, and the "or `custom_properties`" half of that line answered itself: the search runs in SQL, and querying inside a JSON column is written differently on every driver — the same constraint that already decided the type sort and the kind filter. Folders have no `custom_properties` at all, so the free option meant two mechanisms for one feature.

A tag is a row per scope rather than a string per item, because a shared row is what lets the filter offer a closed list and a colour mean one thing everywhere. And a tag nothing carries any more is deleted, which is what means there is no tag management screen: the vocabulary is exactly what is in use.

Items are keyed by `'folder'` / `'file'` rather than by a morph class — the same trap the swappable folder model walked into, avoided this time by not storing a class name at all.

## After 1.0 — additive, and none of it blocking

- **File versioning.** The `replace` conflict policy destroys what was there. Keeping the last N versions would follow the pattern the trash already proved: move aside rather than destroy.
- **Chunked and direct-to-S3 uploads.** `upload.max_size_kb` defaults to 50 MB, but the real ceiling is the host's `post_max_size`. This is what unblocks video and large media.
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
