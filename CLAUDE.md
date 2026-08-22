# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`koassi/filament-file-explorer` — a distributable Composer package (not an application): a Finder-style file explorer for Filament v4/v5, storing files through Spatie Media Library attached to a `Folder` model tree. Namespace `Koassi\FilamentFileExplorer\` → `src/`.

## Commands

```bash
composer install
./vendor/bin/pest                                    # full suite (sqlite :memory:, Testbench)
./vendor/bin/pest tests/Feature/StandalonePageTest.php
./vendor/bin/pest --filter="registers a navigation entry"
./vendor/bin/pint                                    # format (laravel preset + declare_strict_types)
./vendor/bin/pint --test                             # check only

node --test "tests/Js/*.test.mjs"                    # selection / keyboard logic
```

`tests/Js/` has **no dependencies and no build step**: [tests/Js/harness.mjs](tests/Js/harness.mjs) runs `resources/js/file-explorer.js` in a `node:vm` context with just enough of Alpine and the DOM to drive the selection. It exists because the keyboard and selection layer is where the bugs actually are and no PHP test can reach it.

Three things about it are load-bearing:

- Objects cross the vm boundary, so their prototype is not the test realm's — compare them by value (`at()`, `selected()`, `wireCalls()`), never with `deepStrictEqual`.
- `spawn()` puts a **second explorer in the same realm**, sharing the Alpine stores. Two `loadExplorer()` calls give two isolated realms instead, which proves nothing about anything shared.
- `window.Alpine` is **set**, so loading the script runs its bootstrap (`registerQfDragStore()` and friends) the way a browser does. Without it that path never executed here, and a call left pointing at a renamed function threw on page load with every test green.
- The stub **models Alpine's reactivity**: nested objects are handed out wrapped, `$`-prefixed keys are not (they are magics, resolved beside the data). The fake `$wire` throws when a method is invoked on a wrapper, the way the real Livewire proxy effectively breaks. That is not decoration — holding `$wire` as a property of the selection instead of in a closure shipped once with every test green, and it stopped `setSelection` reaching the server, which the `$wire.selectedFolders` watch then read as a server-side clear and wiped the selection with. Arrows and shift+arrows selected and immediately lost it.

`larastan`/`phpstan` are dev dependencies but there is **no `phpstan.neon`** — static analysis is not wired up. Running `./vendor/bin/pint` on the whole repo currently reports pre-existing style drift in several files (import order, strict-type declarations in `config/` and `resources/lang/`); scope formatting to the files you touch rather than reformatting the tree.

There is no `package.json` and no JS/CSS build step. `FilamentAsset::register()` ships `resources/{js,css}/file-explorer.{js,css}` **directly** — there is no `resources/dist`, and re-adding one would only reintroduce the silent drift it used to cause (nothing fails when a copy is forgotten; the change simply never reaches host apps). `filament:assets` publishes the sources to the host's public directory. Host apps re-run it on upgrade, as they do for every Filament plugin.

## Two operating modes

Everything in the package reduces to one Livewire component driven by two values: a **scope key** (authorization + session namespace) and a **root folder id**. The two modes only differ in where those come from.

1. **Standalone (panel-level)** — `FilamentFileExplorerPlugin` registers `Filament\Pages\FileExplorer` and `FileExplorerFiles` on the panel. Scope key and root folder come from a `FileExplorerRootResolver` (`GlobalRootResolver` / `PerUserRootResolver` / `PerTenantRootResolver`). No Eloquent record involved.
2. **Record-scoped** — abstract `Pages\FileExplorerPage` / `Pages\FileExplorerFilesPage` are extended by app classes generated from `stubs/` and registered in a resource's `getPages()`. Scope key is `{model-kebab}.{id}`, root folder is the record's `folder_id`, via the `HasFileExplorer` model trait.

Both share [Pages/Concerns/InteractsWithFileExplorer.php](src/Pages/Concerns/InteractsWithFileExplorer.php), which is the single seam: it declares `fileExplorerScopeKey()` + `resolveFileExplorerRootFolderId()` abstract, sets `$rootFolderId`, and aborts 403 via the authorizer. The page Blade view just hands both values to `<livewire:filament-file-explorer::file-explorer>`. Adding a third mode means implementing those two methods, nothing more.

## Configuration precedence (important)

Standalone settings are read through [Support/StandaloneSettings.php](src/Support/StandaloneSettings.php), never from `config()` directly at call sites. It tries the panel's plugin instance first, then falls back to config. This keeps settings **panel-scoped** in multi-panel apps instead of mutating global config, and keeps things working outside a panel (console, tests) where `FilamentFileExplorerPlugin::get()` throws. Any new standalone setting must be added in three places: the config file, a fluent setter + accessor on the plugin, and a `StandaloneSettings` reader.

Two wrinkles the newer settings introduced:

- A setting whose "unset" value is a legal value needs its own flag. `quota` and `refresh` are nullable — null means *no limit* — so the value alone cannot say whether the panel had an opinion, and `->quota(null)` has to be able to lift a limit config had set. Hence `hasQuota()` / `hasRefreshInterval()` beside the getters.
- `tableColumns()` takes a Closure, which cannot live in a config file, so it is plugin-only. `StandaloneSettings::tableColumns()` still exists as the single reader; it just has no config fallback. The trait keys its columns by name so the callback can add, drop or reorder them.

## Container bindings

Registered in [FilamentFileExplorerServiceProvider.php](src/FilamentFileExplorerServiceProvider.php):

- `FileExplorerAuthorizer` — singleton resolving `config('filament-file-explorer.authorizer')`; the plugin's `->authorizer()` overwrites that config key in `boot()`.
- `FolderTree` — **`scoped`, not `singleton`**: it memoises containment checks, parent lookups and descendant sets for one request. A shared instance in a long-lived worker (Octane) would keep answering for a tree that has since been reorganised.
- `Quota` and `Uploader` — **`scoped`**, for the same reason as `FolderTree`: one memoises how many bytes a scope holds, the other uploader names, and both would be wrong by the next request.
- `FileExplorerRootResolver` — deliberately **`bind`, not `singleton`**: the resolver *class* is panel-scoped so it must be looked up per resolution. The three concrete resolvers are singletons, and that is what memoises the root-folder lookup (`canAccess()` runs on every navigation render, so an unmemoised resolver would hit the DB repeatedly and could create duplicate roots).

## Listing and tree walks

The explorer never loads a folder's contents into memory to sort them. [Support/FolderListing.php](src/Support/FolderListing.php) sorts and windows in SQL and returns `{folders, files, shown, total, hasMore}`; `Livewire\FileExplorer::listing()` calls it with a `perPage` window that `loadMore()` grows and `resetListing()` sends back to one page (`listing.per_page` in config). Folders fill the window first, and the file budget is `perPage - totalFolders` so that widening the window only ever appends. Every `ORDER BY` ends with the primary key: without that tiebreak, rows sharing a sort value can swap between windows and "load more" repeats or skips them. Sorting by *type* orders on `mime_type` because extracting an extension is not portable across drivers.

`resetListing()` is the single hook meaning "the listing may have changed" — every mutation and every navigation calls it — and it also flushes `FolderTree`. That flush matters: creating or deleting a folder changes the set of ids under the root, so a request that mutates and then re-renders would otherwise draw a stale sidebar and search the old scope.

## One page, several explorers

The `FileExplorerPicker` puts an explorer in a modal, so a page can hold two of them. Nothing in the views or the JS may therefore be addressed globally:

- **No `id` attributes**, except ones built from `$this->getId()` (the delete dialog's `aria-labelledby` is the only survivor). `fileInput`, `folderInput`, `folder-container`, `rename-input`, `new-folder-name` and `filemanager-area` were all global: the upload button of one explorer opened the other's file dialog, the marquee measured the wrong container, and the rename focus landed in whichever input the document happened to hold first. Inputs are reached with `x-ref` through a component method (`openFilePicker()`), containers with `this.$root.querySelector('[data-fe-items]')`, and the focus targets with `data-fe-rename-input` / `data-fe-new-folder-input`.
- **Nothing calls `document.getElementById`**, and a test asserts it stays that way.
- **Server-dispatched focus events carry `id: $this->getId()`**, which `focusInput()` compares against its own `componentId` before acting — they travel through the window, so both explorers hear them. It also gives up after 40 attempts: the input never appears if the user cancels before the render lands, and the old handler polled for it forever.

- **The selection is the component's, not the page's.** It used to be one Alpine `feSel` store with one `feWireRef`, so two explorers shared a selection: clicking in the modal moved the page's highlight, and `setSelection` reported to whichever component initialised last. `createFeSelection()` is now a factory and the component holds one as `sel` in its own `x-data`; the views reach it as `sel` because children inherit the Alpine scope. There is no `feSel` store to reach for, and a test asserts it does not come back.

  Two things about that selection are load-bearing, and both were learned the hard way:

  - **It is looked up from `feSelections`, keyed by component id — never built inline in the `x-data`.** The root `x-data` embeds server values, so a Livewire round trip rewrites that attribute and Alpine runs the initialiser again. A selection constructed there dies on every keystroke: the cursor comes back null, `moveSelection()` reads that as "nothing selected", and the arrow jumps to the first item instead of the next. The global store this replaced survived by accident, being registered once and guarded. For the same reason the root `x-data` must **not** carry `selectedFolders` / `selectedFiles` — `init()` seeds from `$wire` instead, which is what stops a selection change from rewriting the attribute at all.
  - **The `$wire` lives in the factory's closure, not as a property.** The selection is reactive `x-data`, and Alpine hands nested objects out wrapped; the Livewire proxy does not survive being wrapped. As a property it stopped `setSelection` reaching the server, and the `$wire.selectedFolders` watch then read the empty server state as a deliberate clear and wiped the selection.

Still shared, and correctly so: the `feDrag` store and its `feWireRef`. A user drags in one explorer at a time, `pointerDown` is handed the selection it started in, and the store holds it for the length of the drag.

## Selection and keyboard

Selection lives on the component, built by `createFeSelection()` ([resources/js/file-explorer.js](resources/js/file-explorer.js)), and is mirrored to that component through a debounced `setSelection` on a `$wire` of its own. Two conventions hold it together:

- Every **selectable** item carries `data-fe-type` (`folder`/`file`) and `data-id`, and the items container carries `data-fe-items` — which is also how the JS and the CSS address that container now that it has no id. `sel.orderedItems(scope)` reads the DOM through those attributes — that is how shift-range and the arrow keys work in the grid *and* the row views without anyone knowing the column count (vertical movement groups items by `offsetTop`). A new view mode has to keep those attributes.

  The column view is what that word *selectable* is doing there: it renders one pane per level of the path, and only the **last** pane — the folder being browsed — carries the attributes. The panes behind it are navigation, so `orderedItems()` never sees them and the keyboard, the shift-range and the marquee go on operating on one folder's contents, as in every other view. Honouring the contract by omission was cheaper than inventing pane-aware arrow keys, and there is nothing to get wrong. `Livewire\FileExplorer::columnPanes()` builds them, costs a listing per level (bounded by `folders.max_depth`), is memoised beside `listingCache` and flushed with it, and returns `[]` while searching — a search answers from the whole scope, which is not a path.
- Keyboard shortcuts are bound on the items container (`@keydown="onKeydown($event)"`), never on the window. A page can hold more than one explorer — the `FileExplorerPicker` in a modal — and a window binding would fire for all of them, including while the user types in the search box.

`sel.click()` owns the modifier logic (shift extends from the anchor, ctrl/meta toggles) so the Blade files stay declarative, and `toggle()` moves the anchor. `replace()` mirrors server state without syncing back; `select()` is the one that syncs, so UI-driven selection must go through it.

**`anchor` and `cursor` are two different things.** The anchor is the fixed end of a shift-range and moves only on a plain click or `toggle()`; the cursor is where the keyboard is, and `selectRange()` moves it to the far end. `moveSelection()` has to read the cursor — reading the anchor for both meant every `shift+arrow` recomputed the same two-item range, so a keyboard selection could never grow past two items.

## Dialogs

The delete confirmation and the preview lightbox live **inside** the Livewire component, not in the host panel's modal stack, because the component is also embedded by `FileExplorerPicker` where no page-level modal exists.

The Get Info inspector **follows the selection**: `refreshInfo()` runs from every entry point that changes it — `setSelection()`, `clearSelection()` and the public `selectFolder()`/`selectFile()` — re-reading the panel for the new selection and closing it with the last item. It is deliberately not called from the render, so a panel the user closed stays closed. `$infoItem['scope']` is what tells the two panels apart: `'selection'` follows, `'current'` (Get Info on empty space, describing the folder being browsed) has no selection behind it and stays put. A refresh that finds the row gone, or the ability revoked, closes the panel rather than turning an ordinary click into a 404 or a 403.

Both keep their state on the server (`$deleteRequest`, `$previewItem`) rather than in Alpine. For the confirmation that is what lets it use real `trans_choice` and report what a recursive delete actually takes with it (`folderContentCount()`) plus the items the authorizer refuses — a `window.confirm` could say none of that, and none of it would be testable. `confirmDelete()` re-enters `deleteItems()`, which stays the only place enforcing the delete rules; the dialog is informative, never authoritative. The lightbox is gated on the `download` ability because it streams the same bytes through the same media route.

Escape is handled once, on the component root (`@keydown.escape.window`), and `trapTab()` in the JS keeps Tab inside whichever dialog is open.

## Trash

`trash.enabled` (on by default) turns deleting into moving aside. [Support/Trash.php](src/Support/Trash.php) owns it, and the two halves work differently on purpose:

- **Folders** use `SoftDeletes`. A trashed folder drops out of every `Folder::query()` the explorer runs, so the listing, the sidebar, the search scope and `descendantFolderIdsIncludingRoot()` need no extra filter. `FolderTree::parentId()` and `Folder::parent()` are the exceptions: they use `withTrashed()`, because containment is about where a folder sits, and a trashed item still has to say where it came from.
- **Files** have no soft deletes in Media Library, so a trashed file moves to `trash.collection` instead. Everything that lists or authorises media already filters on `UploadRules::collection()`, which takes trashed files out of listings *and* out of the media routes for free — the trash is not a download bypass. The file on disk is untouched, so restoring is a database write.

The trash view lists one entry per trashed *thing*: trashed folders whose parent is not itself trashed, and trashed files still sitting in a live folder. A file trashed on its own before its folder went stays in the trash when that folder is restored — `trashed_with_folder` is what distinguishes the two. Restoring re-resolves the file name, since a new upload may have taken it in the meantime.

Restore and purge take the same ability that trashing took (`delete` for files, `deleteFolder` for folders) rather than a new one. That was originally forced: `abilities()` returns a fixed array, and reading a key an existing authorizer never answered denied the action for every host app silently. [Support/Abilities.php](src/Support/Abilities.php) lifted that constraint, but these two keep the ability they had — changing them now would widen access for authorizers written since.

With the trash **off**, `deleteFolderRecursive()` must `forceDelete()`: the model soft-deletes now, so `delete()` would leave rows nothing ever shows again and nothing ever purges.

## Thumbnails

`Folder::registerMediaConversions()` declares one conversion, `thumbnail`, driven from config alone — conversions are registered on the model, which knows nothing about the panel it is browsed from, so this is the one setting `StandaloneSettings` does not own.

Three decisions worth keeping:

- **Served through the media route**, with `?conversion=thumbnail`. `$media->getUrl('thumbnail')` is a raw disk URL: on a public disk it hands the file to anyone with the link, past both guards. The controller only honours a name the media *has generated* (so the value can never be a path from the query string) and falls back to the original when the conversion is missing, which is what keeps a library uploaded before this existed rendering.
- **Content type comes from the conversion**, not the original: Media Library writes a JPG thumbnail for a PNG, and a PDF thumbnail is a JPG too.
- **Not queued by default.** Media Library queues conversions unless told otherwise, and a host with no worker would never get one — the explorer would keep serving originals, which is the whole point of this.

Two ways a conversion can take an upload down with it, both handled: Media Library picks its image generator from the **extension**, so a file merely named `.png` is skipped on its sniffed mime type; and an image GD cannot decode (a truncated upload sniffs as `image/png` all the same) throws `CouldNotLoadImage` *after* the row and the original are written, so it is caught and reported rather than losing an upload that in fact succeeded. Non-image thumbnails (PDF, video) are deliberately not attempted: they need Imagick or ffmpeg on the host, and a failing generator costs more than the icon the explorer already draws.

## Security model

Two independent guards, both required — never add an entry point that skips either:

- **Ability check** — nothing reads `FileExplorerAuthorizer::abilities()` directly; [Support/Abilities.php](src/Support/Abilities.php) is the single reader. `Abilities::ORIGINAL` is the set the contract published (`browse`, `search`, `getInfo`, `download`, `upload`, `mkdir`, `rename`, `move`, `copy`, `delete`, `deleteFolder`) and silence on one of those is still a denial. `Abilities::DERIVED` maps an ability added since to the one it inherits from when the authorizer says nothing — that is what lets the set grow without denying the action for every authorizer already written, and **any new ability must be declared there**, never read raw. Nothing ever defaults to allowed. It is bound `scoped` and memoises: `ability()` is read dozens of times per render. Every mutating Livewire action calls `abort_unless($this->ability(...), 403)`. The delete-state methods additionally support time-window rules.
- **Containment check** — `FolderTree::isUnderRoot($folder, $rootFolderId)` walks parents to prove a folder belongs to the current scope. `Livewire\FileExplorer::assertUnderRoot()`, `MediaController`, and `InteractsWithFileExplorerTable::fileExplorerMediaQuery()` all enforce it, because folder ids and media ids arrive as user input (Livewire params, `?folder=` query string, route bindings).

Media ids are the sharp edge of the containment check. [Support/MediaScope.php](src/Support/MediaScope.php) is the single implementation — `Livewire\FileExplorer::assertMediaUnderRoot()`, `MediaController::mediaBelongsToRoot()` and `Support\Sharing` all go through it rather than writing it out, which is how one of its conditions stops being added in one place and forgotten in another. Clearing a media id requires three things: the row's `model_type` is the `Folder` morph class, its `collection_name` is the explorer collection, and its folder sits under the root. Resolving `Folder::find($media->model_id)` and skipping the check when that lookup returns null — which is what the component used to do — leaves every media row of every other model reachable for rename, move, copy, info and delete.

The containment check is only worth anything if `$rootFolderId` is **not** derived from the thing being checked. The media routes take the scope key as a URL segment, so they resolve the root through [Support/ScopeRoots.php](src/Support/ScopeRoots.php) — the standalone resolver when the scope key is the one it owns, otherwise a session registry that pages and the Livewire component write once `canAccess()` has passed. Deriving the root from the requested media (walking up to its top-most ancestor) or reading it from a query parameter proves only that a folder sits under its own ancestor, which is always true. `MediaController` additionally checks `model_type` and `collection_name`: a media row from another model whose `model_id` collides with an in-scope folder id would otherwise pass containment.

Session state (`currentFolderId.{scopeKey}.{rootId}`, `clipboard.{scopeKey}.{rootId}`, `activeRoot.{scopeKey}`, `filament-file-explorer.roots.{authId}`) is namespaced by scope key, which is why per-user/per-tenant resolvers include the user or tenant key in the scope key. The current folder and the clipboard carry the root id as well, since one scope may offer several roots (see below) and a folder or a copied file of one root means nothing in another.

The `ScopeRoots` registry maps a scope key to a **set** of roots, not one: a media link made under one root of a multi-root scope must keep working after the user switches to another. Every id in that set got there through a `canAccess()` that passed, and the bucket is keyed by the authenticated user, so widening the set never widens access.

## Share links

`share.enabled` (on by default) gives a file a public link. It is the only route in the package with no authenticated user behind it, which changes how the two guards apply: they are **split in time**. The ability is checked in `Livewire\FileExplorer::shareFile()` and the decision is what the row records; containment runs on every request in `Support\Sharing::mediaFor()`, against the `root_folder_id` stored on the row — deriving it from the media would only prove the file sits under its own ancestor.

That split is what makes a link die on its own: a file moved out of scope fails the containment walk, a trashed one leaves `UploadRules::collection()` and fails the same check, a deleted one has no row. Nothing keeps a share in step with the tree, because nothing has to.

`ShareController` takes the token and nothing else — no scope key, no media id, no conversion — and answers 404 identically for a bad token, an expired one, a revoked one and a missing file. Tokens are stored, not signed, because revoking is the half of sharing that matters; revoking keeps the row.

Sharing the same file twice hands back the existing link rather than minting a second, so revoking has one thing to revoke — and `FileShared` therefore fires only for a link that is actually new.

## Quotas

`Support\Quota` caps a scope by root folder, not by application: with the per-user or per-tenant resolver each scope gets an allowance of the same size, which is the only reading of a quota that means anything when the resolver hands out one root each.

Two details carry the weight. **Trashed files count** — they sit on the disk until purged, and a trash that stopped counting would be a way over the cap (delete, re-upload, repeat). And the check **reserves** within the request rather than re-reading the sum: an upload adds rows one at a time, so measuring every file of a multi-file upload against the same stale total would let them all through. `resetListing()` flushes the reservation along with the tree.

Enforcement sits at the two places bytes appear: `updatedFiles()` (after the conflict policy is known, so replacing a file only counts the difference) and `duplicateMedia()`, which returns `false` when a copy does not fit. Both count into `$quotaRefused` and report once per action, not once per file.

## Several roots for one scope

`Contracts\ProvidesMultipleRoots` is optional, like `ResolvesExistingRoot`. The roots it names share the scope key, and therefore **one authorization decision and one ability set** — roots that must be authorized apart belong in separate scopes.

Underneath, the explorer still browses exactly one root: every action compares containment against a single `$rootFolderId`, and that is what makes the check worth anything. `Livewire\FileExplorer::switchRoot()` is the only place the root changes, and it validates the id against `ActiveRoot::offers()` — the resolver's own list — not against the tree, which would let any folder pass as a root. `Support\ActiveRoot` also re-validates the stored choice on every page load, so a root the resolver has stopped offering cannot stay reachable through a stale session.

`FolderTree::isUnderAnyRoot()` exists for one caller: the media routes, which take the scope key from their own URL and must accept a link made under any root of that scope.

## Refresh and cross-component sync

`refresh.seconds` is off by default. The polling is driven from Alpine rather than `wire:poll` so it can stand down while the tab is hidden or an item is being dragged — a morphed DOM cancels a drag in progress.

Most freshness is free: Livewire re-reads models on every request and the listing is queried on every render. What `refreshExplorer()` actually adds is the fallback when the folder being browsed has been deleted or moved out of scope. It deliberately does **not** call `resetListing()`, which would send the window back to the first page and undo a "Load more".

`afterMutation()` is `resetListing()` plus an announcement to the other explorers on the page (the picker in a modal beside the page). Navigation and search stay on `resetListing()` — nothing changed for anyone else. The announcement carries the component id so a component ignores its own event, and it is never dispatched from `refreshExplorer()`: that is what stops two components refreshing each other forever.

Known gap: with the trash off, a folder purged by another session while a user is inside it leaves Livewire unable to restore the model at all (`newQueryForRestoration()->firstOrFail()`), and the component fails until the page is reloaded. Soft-deleted folders restore fine and take the fallback above.

## Root folder creation

`canAccess()` must never create anything. Filament calls it on every navigation render, so resolving the root the creating way wrote a folder each time a menu was drawn, for every authenticated user, including the ones the check was about to deny. `Contracts\ResolvesExistingRoot` is the read-only escape hatch, and it is a **separate interface on purpose**: adding a method to `FileExplorerRootResolver` would break every resolver a host app has already written. `Support\StandaloneAccess` is the one place both standalone pages take that decision — it authorizes with `0` when the scope has no root yet, and mount() creates it. `ScopeRoots` uses the same read-only path: serving a media file must never create a folder.

`StandaloneAccess` also distinguishes the two ways a resolver fails. An `HttpExceptionInterface` (the per-user and per-tenant resolvers abort without a user or tenant) is a plain "not for this request" and stays quiet; anything else is `report()`ed, because the only symptom otherwise is a page missing from the menu.

`FileExplorerManager::ensureRoot()` is the only idempotent path. The folders table has `unique(['parent_id', 'slug'])`, but MySQL does not dedupe rows with `parent_id IS NULL`, so concurrent first hits could each create a root. A `Cache::lock` closes that window and falls through to the unlocked path if the cache store cannot lock. Use `ensureRoot()` rather than `createRoot()` for anything resolver-driven.

## Generators and stubs

`stubs/*.stub` stay inside the package and are read by `MakePageCommand` / `MakeAuthorizerCommand` / `MakeFolderMigrationCommand` via [Commands/Concerns/CopiesPackageStubs.php](src/Commands/Concerns/CopiesPackageStubs.php), which prefers `base_path('stubs/filament-file-explorer/…')` when the host app has published them. `/stubs/filament-file-explorer/` is gitignored (local QA output) while `stubs/` itself is tracked — don't "clean up" either. Changing an abstract page class means checking the matching stub still compiles against it.

## Tests

Pest + Orchestra Testbench, `RefreshDatabase` on sqlite `:memory:`. [tests/TestCase.php](tests/TestCase.php) lists Filament's providers manually and **provider order matters**: `Filament\Support\SupportServiceProvider` must register before `LivewireServiceProvider` (Support binds Livewire's `DataStore` non-shared; Livewire re-binds it shared). Reversing them hands every component a fresh WeakMap and breaks Livewire's error bag on render. The suite also creates the `users`, `projects` and `media` tables inline in `defineDatabaseMigrations()` — Media Library's own migration is not published here.

`UploadedFile::fake()->create()` fakes `getSize()` but writes an **empty** file, so Media Library stores a size of 0 — useless for anything measuring bytes (the quota tests use `createWithContent()` instead, with a `%PDF-1.4` header so the mime guess still passes the upload rules).

Fixtures live in [tests/Fixtures/](tests/Fixtures/): `TestPanelProvider` boots a panel with the plugin, `Project` + `ProjectExplorerPage` cover the record-scoped mode.

## Conventions

- `declare(strict_types=1);` in every PHP file (enforced by `pint.json`).
- User-facing strings go through `__('filament-file-explorer::file-explorer.…')` with entries in both `resources/lang/en` and `resources/lang/fr`.
- Non-obvious decisions are documented as short comments explaining *why* (see the provider-order and resolver-binding comments) — match that style rather than describing what the code does.
- The finder is rendered `dir="ltr"` on purpose (the layout, the drag geometry and the marquee all assume it), so **`rtl:` variants can never match** — there were none left after the last one was removed, and adding one is dead code until the whole layout is made direction-aware.
- Host apps must add `@source '…/vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';` to their Filament theme, so Tailwind classes used in Blade must be literal, not built from variables.
- The toolbar is `flex-wrap: nowrap` in a column that shrinks by 300px when the inspector opens, so **something in it has to give**: the selection count truncates and the search narrows (`min-w-0`, and `min-width: 0` on the input, which otherwise keeps an intrinsic width). Putting `shrink-0` back on `.fe-toolbar__end` makes the toolbar overflow its column, and since the search sits in a `position: relative` wrapper, what overflows paints *over* the inspector instead of under it.

## Foreign agent configs

`~/.codex/config.toml` and `~/.gemini/settings.json` exist on this machine. If you want their MCP servers, slash commands, subagents, skills, or instructions brought into Claude Code, reply `/import` to scan and list what's importable, then `/import --yes=<digest>` (the scan output names the digest) to apply the user-level items.
