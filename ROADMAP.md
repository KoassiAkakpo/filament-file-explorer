# Roadmap to v1.0.0

Everything on this list is done. The four decisions that could not wait closed in v0.2.0, the features planned before 1.0 closed by v0.5.0, and three of the six items parked *after* 1.0 — tags and descriptions, touch support, the storage widget — landed before it too, and have moved up into the shipped list accordingly.

That is the point at which 1.0.0 is the honest number: nothing structural is pending, and the public API is what it is going to be.

This roadmap was never ordered by how appealing a feature is. It is ordered by **what has to be decided before the API freezes** — a feature is cheap to add after 1.0 if it only adds, and impossible to add after 1.0 if it changes something a host app has already written against. Being on `0.x` was the window in which those changes were still free, and it was used for exactly that: the ability set, the folder model, the selection and the events all changed shape in it.

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
- `Support\Annotations`, its `'folder'` / `'file'` item types and its three tables
- `Widgets\StorageWidget`, and the touch gestures the JS now answers to

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

## Features shipped before 1.0

All done. What follows is kept for the record of why each was shaped the way it was — the last three were on the after-1.0 list and were brought forward, which is the right way round: an addition made inside `0.x` can still change its mind about a table or a key.

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

### ~~Touch support~~ — done

The gap turned out to be one line of arithmetic: the drag arms after five pixels, and five pixels is also how far a finger travels when it means to scroll. Dragging a folder to scroll the folder moved the folder.

So the two pointers get different gestures rather than the drag getting a touch translation. A touch never arms a drag; it arms a hold, which opens the context menu — and moving items stays reachable from there through cut and paste, which is a better answer on a tablet than a drag fighting the scroll. The marquee needed nothing: it is bound to `mousedown` and a finger never started one, which was right all along.

Written in the drag store rather than in the five views that hand it a press, for the reason every other rule in this package ended up in one place.

### ~~A storage widget~~ — done

Nearly free, as predicted, and the interesting part was what it must not do: a dashboard renders for every authenticated user, so the widget had to reach the root the read-only way. `StandaloneAccess::scope()` is the counterpart of `granted()` and returns null rather than 0 when there is no root yet — a caller who is not the page has nothing to measure and must never be the one to create it.

Off by default, because a dashboard is not the package's. And it shows the total with no quota set at all: a bar with nothing to fill would only invent a limit.

Wiring it up did find one real bug in the plugin's own pattern — `register()` cannot use the `StandaloneSettings` reader, because the reader resolves the panel's plugin and during `register()` that is not yet this one.

### ~~The picker picks~~ — done

`FileExplorerPicker` was a browser in a modal that stored nothing: `isMultiple()` had no reader and nothing wrote the selection into the field's state. Added to a form it answered **404**, because the root defaulted to `0` and the `findOrFail` behind it treated a root the host never configured as a missing page.

Settled: media ids, one when single and a list when `->multiple()`; a Choose button in the explorer's toolbar beside the count rather than in the modal's footer, so the action sits with the selection instead of mirroring it across two components; and the field's value deliberately kept distinct from the explorer's selection, since the selection is narrowed to what is on screen.

### ~~File versioning~~ — done

The `replace` conflict policy was the one place in the package where something was lost without passing through the trash: the new row was written and the old one deleted outright. It now makes the trade the trash already proved — move aside rather than destroy — and the Get Info inspector lists what is behind a file, newest first, with a restore beside each.

Settled: a **lineage** rather than a name or an id, minted once and never rewritten, which is why rename, move, copy and the trash needed no changes at all. A table rather than `custom_properties`, for the reason the tags are tables — a JSON query is written differently on every driver. The old row is set aside **after** the new one is written, which is what makes a failed replacement leave the file that was there alone; the delete it replaced did the opposite. And `FileVersioned` fires instead of `FileDeleted`, because "a listener has to hear that as a deletion of its own" stopped being true.

Two consequences worth stating plainly. Versions **count against the quota**, exactly like trashed files: they sit on the disk until something purges them, and an allowance that stopped counting them would be a way over the cap. And the description and the tags now **follow the file onto the new row** — replacing quietly dropped both before, and keeping the old row alive would have stranded them somewhere unreachable instead, which is worse.

### ~~Chunked and direct-to-S3 uploads~~ — done

`upload.max_size_kb` defaulted to 50 MB and none of it was reachable. **Five** ceilings stacked and the lowest won: ours, Media Library's 10 MB, Livewire's 12 MB, and PHP's `upload_max_filesize` (2 MB) and `post_max_size` (8 MB). So the honest default was 2 MB against a promise of 50, and going over it failed with whichever spoke first — a 422 the page rendered as "upload failed", or nothing at all when the web server dropped the body before PHP saw it. Nothing on screen ever named a number.

`Support\UploadLimits` is now the single reader of all five, and the split it makes is the design: three cap a *request*, two cap a *file*. Slicing removes the first three from the answer, because no request carries a whole file.

Two things settled it. The slice transport is a **transport** — it ends by writing exactly the temporary file Livewire's own endpoint writes and hands the browser's reference to Livewire's own `_finishUpload`, so `updatedFiles()` runs unchanged rather than gaining a second write path. And it engages **only where it has something to cover**, so an upload that already worked keeps taking exactly the path it took before; the failure mode of a new transport is that it breaks the old one.

Direct-to-storage turned out to be repair rather than feature. `uploadMultiple` throws outright on a remote temporary disk, and the JS called it unconditionally — so on a host configured for S3 the upload button did nothing at all. Then `getRealPath()` answers with an S3 key, which `FileAdder` runs `is_file()` on, so storing a successfully-received upload failed. Both fixed; the staged local copy also keeps the mime type sniffed from bytes rather than read from a `Content-Type` the browser chose, which `UploadRules`, the kind filter and the type sort all depend on.

## Still after 1.0 — additive, and none of it blocking

- **PDF and video thumbnails, opt-in.** Deliberately absent today because they need Imagick or ffmpeg on the host and a failing generator costs more than the icon already drawn. Worth offering behind an explicit flag — never by default.

## Out of scope

- **RTL.** The layout, the drag geometry and the marquee all assume `dir="ltr"`, which is why the finder is rendered that way on purpose. This is not a matter of adding `rtl:` variants — those can never match — but of making the whole layout direction-aware. Large, and narrow in benefit.
- **Full-text search inside documents.** That belongs to Scout or Meilisearch in the host application, not to a file explorer.

## Known gaps to close along the way

- With the trash **off**, a folder purged by another session while a user stands in it leaves Livewire unable to restore the model at all, and the component fails until the page is reloaded. Soft-deleted folders take the fallback correctly.
- The breadcrumb calls `navigateToBreadcrumb()` straight from its handler rather than through the JS `enterFolder()`, so a debounced selection sync could in principle land behind it. `setSelection()` narrowing to the listing catches the consequence, so what is left is a redundant round trip rather than a wrong selection.
- A real S3 round trip is not covered by the suite: Livewire hardcodes `tmp-for-tests` as its temporary disk while tests run, so the component cannot be driven end to end against a remote one. What is covered is the staging seam and the mime-sniffing guarantee, against a disk built to have S3's shape.
- Slicing requires a local temporary disk, because Flysystem has no append and `Storage::append()` joins with a newline. Storing each slice as its own object and concatenating at the end would work anywhere, at the cost of one more full read and write; not done, because the case that needs it is a remote temporary disk, and that is already receiving the browser's bytes directly.
- A sliced upload does not resume across a page reload. The token and its partial survive for `ttl_minutes`, so the pieces are there — what is missing is the browser remembering where it was.
- A kept version cannot be downloaded or previewed, only restored. Reaching one through the media route would mean widening the containment check to accept a second collection, and that check is the sharpest edge in the package — a trashed file is not downloadable for the same reason. Restoring is lossless and reversible, so nothing is unreachable; it just takes two clicks rather than one.
- A share link dies when the file it points at is replaced, because replacing writes a new media row. That was already true when replacing destroyed the old file; versions make the old row survive, so re-pointing the share at the new one is now at least *possible*. Not done, because a share is a link to a file at a moment and silently following the content forward is a decision, not a fix.
- `pint` reports pre-existing style drift in `config/` and `resources/lang/` (strict types, import order). Worth clearing in one pass rather than file by file.
- `larastan` is a dev dependency with no `phpstan.neon`. Static analysis is not wired up, so nothing enforces the type annotations the code already carries.
