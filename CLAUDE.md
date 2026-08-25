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

The explorer never loads a folder's contents into memory to sort them. [Support/FolderListing.php](src/Support/FolderListing.php) sorts and windows in SQL and returns `{folders, files, shown, total, hasMore}`; `Livewire\FileExplorer::listing()` calls it with a `perPage` window that `loadMore()` grows and `resetListing()` sends back to one page (`listing.per_page` in config). Folders fill the window first, and the file budget is `perPage - totalFolders` so that widening the window only ever appends. Every `ORDER BY` ends with the primary key: without that tiebreak, rows sharing a sort value can swap between windows and "load more" repeats or skips them. Sorting by *type* orders on `mime_type` because extracting an extension is not portable across drivers. [Support/FileKinds.php](src/Support/FileKinds.php) owns both the kinds the filter menu offers and the SQL predicate that matches them — one list, so the menu cannot offer a kind the query ignores — and it is applied in `filesQuery()` **before** the window and the counts, which is what makes `shown`/`total` describe the filtered set. Kinds match on `mime_type` for the same portability reason as the type sort, and only files are narrowed. `FolderListing::SORTS` is the single list of accepted sorts — `Livewire\FileExplorer::setSort()` validates against it rather than repeating one — and a sort that means nothing for a folder (*type*, *size*) falls back to its name while still following the direction.

`resetListing()` is the single hook meaning "the listing may have changed" — every mutation and every navigation calls it — and it also flushes `FolderTree`. That flush matters: creating or deleting a folder changes the set of ids under the root, so a request that mutates and then re-renders would otherwise draw a stale sidebar and search the old scope.

## The picker

`Forms\Components\FileExplorerPicker` mounts the Livewire component inside a Filament modal. Two things about it:

- **Its root and scope key default to the standalone library, resolved as a pair.** They used to default to `0` and `'picker'` — which is to say to nothing — so `FileExplorerPicker::make('files')` mounted the explorer on folder #0 and the `findOrFail` in `mount()` answered **404** on whichever page held the form. A default that cannot work is a trap, not a default. The pair is resolved together because abilities are decided per (scope, root) and `ScopeRoots` ties media URLs to the same couple: naming one half must not make the field infer the other, or the picker ends up authorised against a root it is not browsing. It uses the *creating* resolver path, unlike `StandaloneAccess` — that one is read-only because `canAccess()` runs on every navigation render, and a form field does not.
- **A root that does not exist raises `Exceptions\MissingRootFolder`, never a 404.** The root is an argument the host's own code passed, not a route parameter, so a missing one is a misconfiguration — and "No query results for model [Folder] 0" named neither the field nor the fix. Thrown during the field's render, so Blade wraps it in a `ViewException`; the message is what has to carry the diagnosis.

- **The modal is Filament's, and it is opened Filament's way.** The view used to wrap it in an `x-data="{ open: false }"` and pass `x-show="open"`, which put a *second* `x-show` on a root that already carries `x-data="filamentModal(…)"` and `x-show="isOpen"` — so the click flipped a flag nothing watched and the modal never opened, silently, with nothing in the console. It now uses the `trigger` slot, which dispatches the windowed `open-modal` with the modal's own id, so the button and the modal cannot drift apart. `:visible="false"` is gone too: that prop puts `fi-hidden` on `.fi-modal-window`, which is not "closed" — a modal is closed until told otherwise — so it hid the window even once the overlay opened. A test asserts the template carries no `x-data`, `x-show` or `:visible`, reading it with the Blade comments stripped, since the note explaining the mistake names the directives.

- **Picking is the explorer's action and the field's state, joined by a token.** The field passes its own **state path** as `pickerToken` (unique on the page) plus `pickMultiple`; `pickSelected()` dispatches `fe-picked` carrying that token back, and the field's listener filters on it before `$wire.set()`ing its state path and closing its own modal. The token rather than the component id, because Livewire generates the id and the field cannot know it at render time.

  Four decisions inside that:

  - **The Choose button lives in the explorer's toolbar, beside the selection count.** In the modal's footer it would sit apart from the thing it acts on and would have to mirror the selection across two components just to know whether to be enabled.
  - **It calls `sel.flushSync()` before `$wire.pickSelected()`.** The mirror is debounced by 40ms, so clicking a file and then Choose in one breath would otherwise hand the server the selection from *before* the click. Livewire keeps two calls made in the same tick in order, so `setSelection` lands first.
  - **`pickSelected()` proves containment again**, through `MediaScope`, even though `setSelection()` already narrowed to the listing: `selectFile()` is public API and deliberately *not* narrowed, and this is the one place an id leaves the explorer for the host's own state. Files only — a folder is where you look, not what you choose — and the single case takes the *first* of the selection rather than refusing, since someone who lassoed three files and pressed Choose meant to choose.
  - **The field's value is not the explorer's selection**, which is why the modal does not reopen with the value selected. The selection is narrowed to what is on screen; a chosen file whose folder is not being browsed would be dropped on the next sync. `getChosenFiles()` reads the state and resolves it through `MediaScope`, so an id carried over from another scope draws nothing rather than leaking a file name.

- **`->kinds()` restricts what may be chosen, and does it in four places from one predicate.** `FileKinds::applyAny()` is that predicate — added beside `apply()` so the class keeps its promise of one list and one query. It narrows the picker's listing (before the window and the counts), narrows the filter menu through `kindOptions()`, filters `pickSelected()`, and backs the field's validation rule. Expressed in SQL even where the check is "is this one id allowed", because a PHP mime matcher beside it would be a second implementation to drift.

  - **`$pickKinds` is `#[Locked]`.** A public Livewire property is the client's own state, and a limit the client can empty is not a limit. Note the two properties beside it — `$scopeKey` and `$rootFolderId` — are *not* locked; that is pre-existing and separate.
  - **The field's validation rule is the half that decides what is saved.** The state is written from the browser by our own listener, so `pickSelected()`'s guard is only the happy path: nothing stops a crafted request setting the state path to any id. `idsOutsideScope()` answers through `resolveFiles()`, the same resolver the field draws with, so what a user reads back and what a save accepts cannot differ.
  - **An unknown kind throws** from `kinds()`, at render time. Dropping it would leave a restriction that quietly does not restrict, which is the one failure mode a limit must not have.
  - **A filter for an excluded kind narrows nothing** rather than emptying the folder — both in `setKind()` and in `FolderListing`'s constructor, since the value can also arrive from a stored view preference.

  `normaliseState()` is the one owner of the field's shape, used by both `afterStateHydrated` and `dehydrateStateUsing`: an application storing a single id in a column and one storing a list in JSON both fill correctly, and adding or removing `->multiple()` does not strand the old shape.

Its test fixture (`tests/Fixtures/PickerForm.php`) is the one that renders the field in a real Filament schema. Before it existed, every test around the picker built the explorer directly with a root the field itself was failing to resolve, which is exactly how the 404 shipped.

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

- Every **selectable** item carries `data-fe-type` (`folder`/`file`) and `data-id`, and the items container carries `data-fe-items` — which is also how the JS and the CSS address that container now that it has no id. `sel.orderedItems(scope)` reads the DOM through those attributes — that is how shift-range and the arrow keys work in the grid *and* the row view without anyone knowing the column count (vertical movement groups items by `offsetTop`). A new view mode has to keep those attributes.

  `StandaloneSettings::VIEW_MODES` is `grid`, `columns`, `details` — three, since `list` and `table` were variations on `details` with a column dropped. A stored preference naming a mode that is gone falls back to the default, which is what `normalise()` has always done for anything it does not know.

  The column view is what that word *selectable* is doing there: it renders one pane per level of the path, and only the **last** pane — the folder being browsed — carries the attributes. The panes behind it are navigation, so `orderedItems()` never sees them and the keyboard, the shift-range and the marquee go on operating on one folder's contents, as in every other view. Honouring the contract by omission keeps shift-ranges and the marquee working on one folder's contents with nothing to get wrong — but **left and right do navigate**: `moveSelection()` checks `isColumnView()` and calls `columnInto()` / `columnBack()` instead of moving inside the pane. That check reads `data-fe-view` off the items container rather than a value in `x-data`, because the root `x-data` attribute must not carry state that changes — see the selection note above. Folders in every pane carry `data-fe-drop-folder`, since a destination the view shows has to be one you can drop on, and each pane clears the selection on a click in its own empty space: the panes fill the container, so `handleContainerClick()` never receives that click any more. `Livewire\FileExplorer::columnPanes()` builds them, costs a listing per level (bounded by `folders.max_depth`), is memoised beside `listingCache` and flushed with it, and returns `[]` while searching — a search answers from the whole scope, which is not a path.
- Keyboard shortcuts are bound on the items container (`@keydown="onKeydown($event)"`), never on the window. A page can hold more than one explorer — the `FileExplorerPicker` in a modal — and a window binding would fire for all of them, including while the user types in the search box.

**Opening a folder goes through `enterFolder()`, never `$wire.navigateToFolder` from a handler.** A double-click is a click first, and that click queued a debounced `setSelection`: the navigation goes out on the second click and the sync 40ms after it, so the server cleared the selection and was then told to select the folder just left. The `$wire.selectedFolders` watch read that back as a real selection and the toolbar reported "1 selected" over a folder that does not appear in its own listing. `enterFolder()` drops the queued sync and clears locally before navigating; the sidebar uses it too, so "open this folder" is one path. A test asserts the templates never call `navigateToFolder` directly.

**`setSelection()` narrows to the current listing, and it is the only setter that does.** It is the browser's mirror: the sync is debounced, so a request can arrive after the component has moved on and then name items of the folder just left. `enterFolder()` closes the one path where that race was guaranteed; this closes the ones nobody has found yet, and the ones that only happen on a slow connection. A selection of something the listing does not show is not a selection. It costs nothing — the render computes the listing anyway and this reads the memoised copy — and the empty case short-circuits before touching it.

`replaceSelection()` is the server's own setter and does **not** narrow, because the two callers want different things: a delete *names* its targets, and both the current folder and the root are legitimate ones that a listing of their own contents can never contain. `requestDelete()` used to route through `setSelection()`, which is how narrowing first broke deleting the folder you are standing in. `selectFolder()` / `selectFile()` — the public API — are not narrowed either: an application selecting an item it is about to navigate to means it.

The debounce timer has **one owner** for the same reason: `cancelSync()` / `syncPending()` on the selection, where three places outside it used to reach in and clear `_syncTimer` themselves. Dropping a pending sync is a decision, not a detail.

`sel.click()` owns the modifier logic (shift extends from the anchor, ctrl/meta toggles) so the Blade files stay declarative, and `toggle()` moves the anchor. `replace()` mirrors server state without syncing back; `select()` is the one that syncs, so UI-driven selection must go through it.

**`anchor` and `cursor` are two different things.** The anchor is the fixed end of a shift-range and moves only on a plain click or `toggle()`; the cursor is where the keyboard is, and `selectRange()` moves it to the far end. `moveSelection()` has to read the cursor — reading the anchor for both meant every `shift+arrow` recomputed the same two-item range, so a keyboard selection could never grow past two items.

## Touch

A finger and a mouse get **different gestures**, because the drag arms at 5px and that is also how far a finger moves when it means to scroll — on a tablet, dragging to scroll a folder moved the folder.

`feDrag.coarse` is set in `pointerDown` from `event.pointerType !== 'mouse'` and decides everything:

- **A touch never arms a drag.** `pointerMove` returns early for a coarse pointer, so nothing calls `preventDefault()` and the browser goes on scrolling. Moving items stays reachable through cut/paste, which the hold's menu offers.
- **A hold opens the item's context menu**, by dispatching the same `fe-context` event the right-click handler dispatches. `armLongPress()` lives in the drag store and not in the views because `pointerDown` is already the single place every view hands a press to — grid, both row views, column panes — and a long press written five times behaves five ways. It also sets `suppressClick`, or the release lands as a plain click that re-selects the item alone under the open menu.
- **A hold on empty space** is the component's (`onEmptyPointerDown`), since there is no item to hand the press to. It arms nothing for a mouse: right-click already does this, and a mouse press on empty space is how the marquee starts.
- **The marquee is left alone.** It is bound to `@mousedown.left`, so a finger never starts one — which is correct, a finger dragging across empty space should scroll.

`FE_LONG_PRESS_MS` (500) and `FE_TOUCH_SLOP` (10) are the two constants; the slop is what stops a finger's natural drift from cancelling its own hold.

CSS side: `touch-action: manipulation` on `[data-fe-items]` is what makes `dblclick` fire on a tablet at all (it drops double-tap-to-zoom and keeps pan/pinch), `-webkit-touch-callout: none` stops iOS opening its callout over ours, and `@media (pointer: coarse)` grows the controls.

The harness models this: `setTimeout` is a queue that fires only through `runTimers()`, so the debounced `setSelection` stays unfired for the tests that count `$wire` calls while a long press can elapse on demand. Setting `coarse` to false fails four of the eleven touch tests.

## Dialogs

The delete confirmation and the preview lightbox live **inside** the Livewire component, not in the host panel's modal stack, because the component is also embedded by `FileExplorerPicker` where no page-level modal exists.

The Get Info inspector **follows the selection**: `refreshInfo()` runs from every entry point that changes it, re-reading the panel for the new selection and closing it with the last item. There are only two doors into a cleared selection, and every caller has to use one of them:

- `clearSelection()` — the browser asking, so no announcement is needed; it already dropped its own copy.
- `forgetSelection()` — the component deciding: a navigation, a search, the trash, a delete, a move. Same clear, plus the `fe-sel-cleared` the browser has to hear.

That second one exists because **nine** places used to empty `$selectedFolders` / `$selectedFiles` themselves and dispatch the event by hand, and every one of them therefore skipped `refreshInfo()`. Only one was ever reported — the trash button leaving the panel describing a file the trash view does not show — but it was the same bug nine times. The setting side has the same rule: `saveNewFolder()` selects the folder it just made and calls `refreshInfo()` for it, and a test caps how many direct assignments are allowed to exist. It is deliberately not called from the render, so a panel the user closed stays closed. `$infoItem['scope']` is what tells the two panels apart: `'selection'` follows, `'current'` (Get Info on empty space, describing the folder being browsed) has no selection behind it and stays put — with one exception, `toggleTrash()`, which closes it outright: a `'current'` panel stays put because nothing about the folder changed, and entering the trash means the folder is not on screen at all. A refresh that finds the row gone, or the ability revoked, closes the panel rather than turning an ordinary click into a 404 or a 403.

Both keep their state on the server (`$deleteRequest`, `$previewItem`) rather than in Alpine. For the confirmation that is what lets it use real `trans_choice` and report what a recursive delete actually takes with it (`folderContentCount()`) plus the items the authorizer refuses — a `window.confirm` could say none of that, and none of it would be testable. `confirmDelete()` re-enters `deleteItems()`, which stays the only place enforcing the delete rules; the dialog is informative, never authoritative. The lightbox is gated on the `download` ability because it streams the same bytes through the same media route.

Escape is handled once, on the component root (`@keydown.escape.window`), and `trapTab()` in the JS keeps Tab inside whichever dialog is open.

## Trash

`trash.enabled` (on by default) turns deleting into moving aside. [Support/Trash.php](src/Support/Trash.php) owns it, and the two halves work differently on purpose:

- **Folders** use `SoftDeletes`. A trashed folder drops out of every `Folder::query()` the explorer runs, so the listing, the sidebar, the search scope and `descendantFolderIdsIncludingRoot()` need no extra filter. `FolderTree::parentId()` and `Folder::parent()` are the exceptions: they use `withTrashed()`, because containment is about where a folder sits, and a trashed item still has to say where it came from.
- **Files** have no soft deletes in Media Library, so a trashed file moves to `trash.collection` instead. Everything that lists or authorises media already filters on `UploadRules::collection()`, which takes trashed files out of listings *and* out of the media routes for free — the trash is not a download bypass. The file on disk is untouched, so restoring is a database write.

The trash view lists one entry per trashed *thing*: trashed folders whose parent is not itself trashed, and trashed files still sitting in a live folder. A file trashed on its own before its folder went stays in the trash when that folder is restored — `trashed_with_folder` is what distinguishes the two. Restoring re-resolves the file name, since a new upload may have taken it in the meantime.

The trash is a **mode, not a folder**, and two things follow from that:

- **The toolbar stops offering what acts on the folder being browsed.** A new folder and an upload landed in the folder behind the trash — invisible, and indistinguishable from a failure — and the sort, the filters, the view switcher and the search belong to a listing the trash is not. All of them are behind `@unless ($showTrash)`. The selection-gated controls need no guard: the trash rows carry no `data-fe-type` / `data-id`, so no selection can form and their own `x-show` keeps them hidden.
- **A navigation leaves the trash.** `navigateToFolder()`, `navigateToParent()`, `navigateToBreadcrumb()`, `openFolderFromHistory()` and `switchRoot()` each clear `showTrash`. The sidebar tree stays clickable while the trash is up, and without this a click on a folder changed the folder underneath while the screen went on showing the trash — a click that appeared to do nothing. It is also what makes the mode escapable the obvious way.

`createNewFolder()` returns early while the trash is showing, and that is **not** a security guard: `showTrash` is a public property, so it is the client's own state and a check on it proves nothing. What it prevents is a wedge — the inline name input renders inside the items container, which the trash view does not render, so the component would sit in "creating" with nothing on screen to type into or cancel. The rest of the actions need no such check: their entry points are all inside the items container, which the trash view replaces.

Asserting about the toolbar means **scoping to its own markup** (`Str::between($html, 'class="fe-toolbar', 'class="fe-browser')`). The whole translation file is embedded in the root `x-data`, so a label assertion against the full page finds every string whether it is rendered as a control or not — which is how the first version of that test failed for the wrong reason.

Restore and purge take the same ability that trashing took (`delete` for files, `deleteFolder` for folders) rather than a new one. That was originally forced: `abilities()` returns a fixed array, and reading a key an existing authorizer never answered denied the action for every host app silently. [Support/Abilities.php](src/Support/Abilities.php) lifted that constraint, but these two keep the ability they had — changing them now would widen access for authorizers written since.

With the trash **off**, `deleteFolderRecursive()` must `forceDelete()`: the model soft-deletes now, so `delete()` would leave rows nothing ever shows again and nothing ever purges.

## File versions

`versions.enabled` (on by default) turns the `replace` conflict policy from *destroys the old file* into *keeps the last N*. It only ever engages on that policy — the default is `rename`, which keeps both under different names — so on a default install none of this runs. [Support/Versions.php](src/Support/Versions.php) is the only reader and writer, bound `scoped` because it memoises one lineage's history, which the inspector re-reads on every render while its panel is open.

The **mechanism is the trash's**, and for the reasons that made it work there: the version's media row changes collection, so every listing, the flat files table, `MediaScope` and the media routes filter it out for free — a version is not a second way to download a file, exactly as a trashed one is not. The bytes on disk are untouched, so restoring is a database write. `Versions::collection()` refuses to equal either the live or the trash collection, guarded rather than documented: sharing the live one would make versions part of the folder they are the history of, and the trash's would put them in a view where restore means something else.

What is new is the **lineage**, and every other decision follows from it:

- **Identity is a lineage, minted once and never rewritten.** Not the media id — replacing writes a *new* row, so that key would have to be rewritten across the whole chain on every replacement, and a key that is rewritten is a key that drifts. Not the folder and the file name either — a rename or a move would cut the chain in half. Which is why rename, move, copy and the trash needed **no changes at all**.
- **A table, not `custom_properties`.** The same portability constraint as the tags: this is read in SQL and a JSON query is written differently on every driver. The live row has a line too — that is the only place its lineage can be read from.
- **`replaced_at` is null on exactly one row of a lineage: the live one.** So "is this the current file" is a condition rather than a join, and one that cannot disagree with the collection the row sits in.
- **`sequence` is stored, not derived from the order of the ids.** Restoring moves a version back to the head, and its number has to say so — after restoring v1 out of [v1, v2, v3], v1 is v4.

Three things in the upload path carry weight:

- **The old row is set aside *after* the new one is written.** The delete it replaced did the opposite, which meant an `addMedia` that threw left the folder with neither file. This way a failed replacement leaves the file that was there exactly where it was — which is the property the whole feature is for. The two rows share a `file_name` for the length of one statement, and that costs nothing: each media row owns a directory of its own on disk, and no other request can see between the two writes.
- **Replacing no longer frees what it took**, so the quota is charged the *whole* incoming size rather than the difference. `Quota::collections()` is the single list behind both `usedBytes()` and `fileCount()` — versions count for the reason trashed files do, and an allowance that stopped counting them would be a way over the cap: upload over the same name, repeat.
- **`FileVersioned` fires instead of `FileDeleted`.** The old comment said "replacing destroys the old file outright, trash or no trash: a listener has to hear that as a deletion of its own" — true then, a lie now. A listener watching for deletions must not be told a file was lost when its bytes are one click away. With versioning off, the original path and the original event are exactly as they were.

And one consequence that was a pre-existing bug the feature would have made worse: **the description and the tags follow the file onto the new row** (`Annotations::moveItem()`). `replace` dropped both before, because the annotations are keyed by media id and replacing writes a new one. Keeping the old row alive would have stranded them on a row nothing on any screen can reach, which is worse than losing them.

`restore()` is the same trade in the other direction — the row that was live becomes the newest entry of the history rather than being destroyed, so restoring the wrong version costs one more click and nothing else. Two details:

- **The restored row takes the live row's name**, not the one it carried when it was set aside. A lineage is one file and its name is the live one: someone restoring last week's content does not want the file renamed back to what it was called then. The version rows keep their own names, so the history still says what each was called.
- **It refuses a lineage with no live row in the explorer's collection** — the file is in the trash, or gone. Restoring the file is the trash's job, and doing it from this panel would put back something the user deleted.

Two new abilities in `Abilities::DERIVED`: `viewVersions` ← `getInfo` (reading a history is reading about the file) and `restoreVersion` ← `upload` (making a version current decides what the folder holds; *not* `delete`, since nothing is destroyed).

Cleanup is a **model listener** (`forgetVersionsOfDeletedFiles()`), separate from the annotations one because that one deliberately ignores rows outside the live and trash collections and a version sits in neither. Deleting the live row destroys the history behind it; deleting a version drops only its own line, which is what makes the recursion terminate. Trashing does *not* — a file has to come back with its history.

Two things in the component are load-bearing:

- **`loadInspectorState()` is one call, not one per panel at each of `showInfo()`'s four branches.** Same rule as `forgetSelection()`: this package has already paid for the other arrangement, where nine places emptied the selection by hand and every one forgot `refreshInfo()`.
- **`restoreVersion()` moves the selection onto the row it restored**, through `replaceSelection()`. The restored row is a *new* media id; leaving the selection on the one just set aside leaves the inspector describing a row that has left the listing, and `refreshInfo()` answers that with a **403** rather than a panel — `showInfo()`'s containment check aborts, and `refreshInfo()` only catches `ModelNotFoundException`.
- **Containment goes through `MediaScope::folderUnderRootInCollection()`**, which names the collection rather than defaulting it. That is what keeps widening this class from widening `folderUnderRoot()` by accident: the media routes, the share links and every other action go on requiring the live collection, and the one caller that means to reach a version says so while still proving the other two conditions.

## Large uploads

`upload.max_size_kb` was a promise, not a ceiling. Five limits stack and the lowest wins: ours (50 MB), `media-library.max_file_size` (**10 MB**), `livewire.temporary_file_upload.rules` (**12 MB**, validated in Livewire's own controller before this component is reached), `upload_max_filesize` (**2 MB**) and `post_max_size` (**8 MB**, which caps the whole request body). So the real default was 2 MB, and going over it failed with whichever spoke first — a 422 the page rendered as "upload failed", or nothing at all when the web server dropped the body before PHP saw it.

[Support/UploadLimits.php](src/Support/UploadLimits.php) is the single reader of all five, and the split it makes is the whole design: three of them cap a **request**, two cap a **file**. Slicing takes the first three out of the answer, because no request carries a whole file. `maxUploadBytes()` is the promise, `perRequestBytes()` is what one request can carry, and the gap between them is exactly what the slice transport exists to cover.

Details in that class that are load-bearing:

- **`sliceableDisk()` resolves the disk the way Livewire's own `isUsingS3()` does** — `config('livewire.temporary_file_upload.disk') ?: config('filesystems.default')` — and deliberately *not* through `FileUploadConfiguration::disk()`, which answers `tmp-for-tests` while a suite is running. A check that describes the harness instead of the host is a check that only ever fails in tests, and this one did.
- **`chunkingEngages()` is three questions, not one**: the setting is on, the temporary disk is local, and a whole file does not already fit in one request. The last is what keeps the transport off the path of uploads that were already working.
- **`bindingConstraint()` names the setting**, because a refusal that gives only a number leaves the host hunting through four config files.

### The slice transport

[Support/ChunkedUploads.php](src/Support/ChunkedUploads.php) plus [Http/Controllers/UploadChunkController.php](src/Http/Controllers/UploadChunkController.php). What matters about it is what it is **not**: a second way to store a file. It ends by writing exactly the temporary file Livewire's endpoint would have written — same directory, same hashed name, same `.json` sidecar — and returns the same signed reference; the browser then calls Livewire's own `_finishUpload` with it, so `updatedFiles()` runs unchanged. A route that stored the file itself would be the second write path this package keeps refusing to grow.

- **Slices arrive in order, one at a time.** Parallel slices would need a received-set and a lock around it; in order, the state is one counter and a wrong index is a 409 rather than a corrupted file. The bottleneck of a browser upload is the uplink.
- **Appending is a raw `fopen(…, 'ab')`, never `Storage::append()`** — that one joins with a newline and would put one byte between every slice, corrupting every binary file that went through it. That is also why slicing requires a **local** disk: Flysystem has no append, and the one case that would need it (a remote temporary disk) is already receiving the browser's bytes directly.
- **Partials live in their own directory**, never Livewire's: `cleanupOldUploads()` deletes anything in there older than a day, and a partial is not a temporary upload until it is whole.
- **The token is server-issued and the path is `sha1($token)`.** Nothing the client sends reaches a filename.
- **State is the session**, keyed under one entry so pruning can see every open upload at once. Which also settles "whose upload is this" by construction — and a token that leaked into a log is worth nothing without the session that opened it.
- **`begin` decides, `chunk` carries.** The ability, the containment walk and an early quota refusal happen once; the slices prove only that they hold a token this session was given. Same split in time as a share link, and for the same reason — but the authoritative checks are still the ones in `updatedFiles()`. This route refuses early so nobody spends ten minutes uploading a file that was never going to land.
- **`append` caps at the size `begin` authorised**, not merely at what the installation accepts. The declared size is what the ceiling and the quota were checked against, so without that cap a client could declare a kilobyte, pass both, and then send a gigabyte — the early refusal would be the very thing that let it through.
- **The route is registered on the *setting*, not on `chunkingEngages()`.** That method reads php.ini and Livewire's disk, and a route table cached on one host and served on another must not depend on either; the controller asks the live question per request.

### Direct-to-storage

Configured in Livewire (`temporary_file_upload.disk => 's3'`), not here. Two things in this package were outright broken there:

- **`uploadMultiple` throws** (`S3DoesntSupportMultipleFileUploads`) — Livewire presigns one URL per upload — and the JS called it unconditionally, so the upload button did nothing at all, with an exception that bypasses the view handler. `UploadLimits::singleFileUploads()` is what the browser reads to send one file per request instead.
- **`getRealPath()` answers with an S3 key**, and `FileAdder` does `is_file()` on it, so `addMedia()` threw `FileDoesNotExist` for an upload that had in fact arrived. [Support/Uploads.php](src/Support/Uploads.php) stages it into a local file first.

`Uploads::readable()` returns the file untouched when it is already a local file — which is not an optimisation. The authoritative `UploadRules::isAllowedUpload()` check in `updatedFiles()` runs on whatever comes back, and for a local upload that has to be the **same object** the validator already saw, so the two answers are necessarily identical rather than merely probably so.

Staging rather than `addMediaFromDisk()`, for two reasons: that adder has a terminal call of its own (`toMediaCollectionFromRemote()`), so taking it would fork the write path at both ends; and it reads the mime type from the object's stored `Content-Type`, which for a presigned PUT is a header **the browser chose**. `UploadRules` refuses SVG on purpose, and the kind filter and the type sort both read `mime_type` — a client-declared type would make all three advisory. Hence the second `isAllowedUpload()` call inside the loop: same single implementation, called where the answer can be trusted.

The bytes cross the server once, as a stream rather than a request body, so no PHP limit applies — the hop those limits were about is still gone.

`FileIsTooBig` is caught beside `CouldNotLoadImage` now. It is Media Library's own 10 MB ceiling, and reaching it means `UploadLimits` and the browser disagree with the config — an upload is not the place to answer that with a stack trace.

### Testing this

`tests/Feature/UploadLimitsTest.php` covers the five ceilings and which one wins; `tests/Feature/ChunkedUploadTest.php` drives the real route, and its "joins the slices into exactly the file that was sent" test is the one that catches a newline between slices — it needs content **larger than `MIN_CHUNK_BYTES`** (256 KB) to get more than one slice, since the floor is deliberate.

`tests/Feature/RemoteUploadDiskTest.php` simulates a remote disk out of the local adapter: a `FilesystemAdapter` whose `path()` prefix is not a directory while its `readStream()` still works, which is exactly S3's shape. **A real S3 round trip is not covered** — Livewire hardcodes `tmp-for-tests` as the disk under a test suite, so the component cannot be driven end to end against a remote one. What is covered is the staging seam and the mime-sniffing guarantee.

`tests/Js/uploads.test.mjs` covers which transport is chosen and the request sequence a browser would actually make. The harness gained a recording `fetch`, a `FormData`, and `uploadMultiple` / `upload` / `uploadFolderId` on the `$wire` stub; `answerWith()` replaces what the slice requests answer.

## Thumbnails

`Folder::registerMediaConversions()` declares one conversion, `thumbnail`, driven from config alone — conversions are registered on the model, which knows nothing about the panel it is browsed from, so this is the one setting `StandaloneSettings` does not own.

Three decisions worth keeping:

- **Served through the media route**, with `?conversion=thumbnail`. `$media->getUrl('thumbnail')` is a raw disk URL: on a public disk it hands the file to anyone with the link, past both guards. The controller only honours a name the media *has generated* (so the value can never be a path from the query string) and falls back to the original when the conversion is missing, which is what keeps a library uploaded before this existed rendering.
- **Content type comes from the conversion**, not the original: Media Library writes a JPG thumbnail for a PNG, and a PDF thumbnail is a JPG too.
- **Not queued by default.** Media Library queues conversions unless told otherwise, and a host with no worker would never get one — the explorer would keep serving originals, which is the whole point of this. One setting for every kind, deliberately: a PDF or a video conversion is seconds rather than milliseconds, so whoever turns those on is choosing between an upload that waits and a queue that has to be running, and that is the same decision either way.

[Support/Thumbnails.php](src/Support/Thumbnails.php) is the single owner of every question about them, and it separates two that used to be one:

- **`wanted($media)`** decides whether to *register* the conversion — read only by `registerMediaConversions()`.
- **`drawable($media)`** decides whether to *render* one — read by the grid component, the column panes, the row views and the inspector.

Those four sites each wrote out `str_starts_with($mime, 'image/')`, which was correct while images were the only kind that could have a conversion and stops being correct the moment a PDF can. `drawable()` is `is an image OR has a generated conversion`, and **both halves are load-bearing**: an image with no conversion is still drawable, because the media route falls back to the original and that fallback is what keeps a library uploaded before thumbnails existed showing its pictures; anything else is drawable only when a conversion really exists, because an `<img>` pointed at a PDF renders nothing and guessing from the mime type would put a broken image where the icon belongs, on every PDF, on every host whose generator is not installed.

`thumbnails.kinds` (`['image']` by default) is what opens PDF and video. Two conditions, not one — the kind has to be listed **and** `available($kind)` has to hold:

- **`available()` asks Media Library's `image_generators` registry**, not `class_exists(Imagick::class)`. That registry is config, so a host may register a generator of their own and a hardcoded class check would report their working setup as unavailable.
- **Then it extends the answer, for the package's own two generators only.** `Pdf::requirementsAreInstalled()` checks that `spatie/pdf-to-image` is installed and never that Imagick has a Ghostscript delegate; `Video::requirementsAreInstalled()` checks for the FFMpeg class and never for the ffmpeg binary. Those are exactly the two ways a host ends up with a generator that reports itself ready and then throws. `Imagick::queryFormats('PDF')` answers the first without a shell; `is_executable()` on `media-library.ffmpeg_path` answers the second. Matched on `$generator::class`, so a host's own generator is taken at its word.
- **An unknown kind is dropped, not thrown on** — the opposite of `FileExplorerPicker::kinds()`. That one is a restriction, and a restriction that quietly does not restrict is the failure mode a limit must not have; this is an enablement, so a typo that enables nothing errs the safe way. It is also read from a model's conversion registration, where throwing would take down every page rather than one form. `Thumbnails::unavailable()` and the install command are how a host finds out instead.
- **`kindOf()` reads the sniffed mime type, never the extension**, which is the rule this whole area already followed: Media Library picks its generator from the extension, so a file merely named `.pdf` would be handed to Ghostscript.

**`conversionCasualty()` is what made the two new kinds safe to offer at all.** A conversion runs *after* the media row and the original are written, so the presence of the row is what says the write succeeded and only the thumbnail was lost — **that probe is the discriminator, not the exception class.** It used to be `catch (CouldNotLoadImage)`, which was exactly right while images were the only kind converted: it is what spatie/image throws for a truncated upload that sniffs as `image/png` and that GD then refuses to decode. It stops being right the moment Ghostscript, Imagick or ffmpeg can throw — a missing binary, a delegate that is not compiled in, a timeout on a long video, none of them a `CouldNotLoadImage`. Named catches would have meant collecting that list from three projects and keeping it current, and every name missing from it loses an upload that in fact succeeded, which is precisely the cost that kept these kinds off. Nothing is swallowed blind: an empty probe rethrows, so a genuine failure to store keeps its exception and its stack. Both the upload path and the copy path go through it.

Media Library picks its image generator from the **extension**, so a file merely named `.png` is skipped on its sniffed mime type — that check lives in `kindOf()` now.

Testing it: neither `spatie/pdf-to-image` nor `php-ffmpeg` is a dependency, and requiring either as a dev dependency would make the suite need Ghostscript or an ffmpeg binary on every machine. `tests/Fixtures/StubPdfThumbnailGenerator.php` (writes a real JPG with GD) and `tests/Fixtures/ThrowingThumbnailGenerator.php` (reports itself ready, then throws) go into `media-library.image_generators` the same way a host's own would — which is also what makes `available()` answer true for them. **Media Library's own PDF and video generators are not covered**; they are its code and tested there. What is covered is everything the explorer does around one.

## Tags and descriptions

`annotations.enabled` (on by default). [Support/Annotations.php](src/Support/Annotations.php) is the only reader and writer, bound `scoped` because it memoises the tags of the rows on screen.

Three tables, not `custom_properties`: **the search runs in SQL**, and querying inside a JSON column is written differently on every driver — the same portability constraint that made the type sort and the kind filter match on `mime_type`. Folders have no `custom_properties` either, so that route meant two mechanisms for one feature.

Decisions that carry weight:

- **Items are discriminated by `'folder'` / `'file'`, never by a morph class.** The folder model is swappable and media rows belong to Media Library's own model, so a class name stored in `item_type` would either break on a swap or need the same `getMorphClass()` care media rows need. Those two words are already the package's vocabulary, from `data-fe-type` down to the clipboard.
- **A tag is a row, per scope.** Free-text strings per item give three spellings of "Urgent" in a week; a shared row is what lets the filter offer a closed list and a colour mean the same thing everywhere. Uniqueness is decided on the **slug**, so case is not a second tag.
- **A tag nothing carries any more is deleted** (`pruneTag()`). That is what means there is no tag management screen to build: the vocabulary is exactly what is in use, and the filter menu can never offer a word matching nothing. It also means the active filter can point at a tag that has ceased to exist — `activeTagId()` re-validates on every listing and clears it, which is also what stops a tag id from another scope narrowing anything.
- **Colours are palette names, never hex.** `Annotations::COLORS` is the closed list and `colour()` the only reader; an unrecognised value becomes null and draws the neutral dot rather than reaching the page. The inspector picks one when adding a tag (`$tagColor`, deferred radios so the choice travels with the `addTag` request), and the colour lands on the **word**: `attach()` recolours an existing tag through `recolour()`, because a colour that meant one thing on one item and another elsewhere would mean nothing. Which is why **null is not grey** — grey is one of the seven choices, none is the absence of a choice and leaves a coloured word alone. The swatch for it is a slashed circle, or the two would be indistinguishable. `$tagColor` is reset after each add: kept, it would recolour the *next* word too. And its radio group name carries the component id — a page can hold two explorers, and one shared group would let each clear the other's choice.
- **The search escape rule has one owner.** `FolderListing::LIKE_ESCAPE` and `FolderListing::likePattern()` are public for exactly one caller, `Annotations::orWhereMatches()`. Two copies of it would drift, and the annotation search would quietly become the one place `%` still meant "anything".
- **Cleanup is a model listener, not a call at each delete site.** `FilamentFileExplorerServiceProvider::forgetAnnotationsOfDeletedItems()` listens to `Media::deleted` and `forceDeleted` on the *configured* folder model — Eloquent fires model events under the class of the instance, so a listener on the package's `Folder` would never hear from a host app's subclass. `forceDeleted` and not `deleted`, because a soft-deleted folder is in the trash and has to come back annotated. Both listeners check the row is **ours** first, and that is not an optimisation: an annotation is keyed by `(kind, id)`, so a media row of another model with a colliding id would otherwise take one of our files' descriptions with it.
- **The description saves through `updatedDescription()`**, Livewire's own hook, not a `wire:blur` action beside `wire:model.blur`. Both travel on the same event and nothing orders them, so an action could read the value from before the edit.

Applied in `FolderListing` before the window and before the counts, like the kind filter, so `shown`/`total` describe the filtered set. Unlike the kind filter it narrows **folders too** — a folder can be tagged, and hiding folders would hide exactly what the user tagged.

And unlike the kind filter it **widens the containment**: a tag filter answers from `subtreeFolderIds()` — everything under the folder being browsed — rather than from its direct children. A tag is a property of the item, not of the folder it happens to sit in, so narrowing to one level made a tag findable only from the one folder that already showed the item, which is the folder where a filter was least needed. The subtree of *where you stand* rather than the whole scope, so navigating while filtered still means something. Three things follow, and all three are load-bearing:

- **The folder being browsed is excluded** from its own folder listing (`id != currentFolderId`), because it is in its own subtree — exactly as the search excludes the root from `scopeFolderIds()`.
- **`filteringByTag()` is the same condition `applyTag()` uses**, annotations-enabled check included. With the feature off a leftover tag id narrows nothing, and a listing that widened anyway would answer a filter nobody applied with every file in the tree.
- **`columnPanes()` returns `[]` while a tag filter is on**, for the reason it already did while searching: panes are a path and a subtree answer is not one, so the same deep item would appear in every pane, each contradicting the one beside it. The view falls back to the flat listing on its own — `$panes !== []` already guards it.

`tagIndex()` prefetches both kinds in two queries because the rendered rows ask per item; a window is a hundred rows.

## Security model

Two independent guards, both required — never add an entry point that skips either:

- **Ability check** — nothing reads `FileExplorerAuthorizer::abilities()` directly; [Support/Abilities.php](src/Support/Abilities.php) is the single reader. `Abilities::ORIGINAL` is the set the contract published (`browse`, `search`, `getInfo`, `download`, `upload`, `mkdir`, `rename`, `move`, `copy`, `delete`, `deleteFolder`) and silence on one of those is still a denial. `Abilities::DERIVED` maps an ability added since to the one it inherits from when the authorizer says nothing (`share` ← `download`, `annotate` ← `rename`, `viewVersions` ← `getInfo`, `restoreVersion` ← `upload`) — that is what lets the set grow without denying the action for every authorizer already written, and **any new ability must be declared there**, never read raw. Nothing ever defaults to allowed. It is bound `scoped` and memoises: `ability()` is read dozens of times per render. Every mutating Livewire action calls `abort_unless($this->ability(...), 403)`. The delete-state methods additionally support time-window rules.
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

## The storage widget

`Widgets\StorageWidget` is opt-in (`standalone.storage_widget`, or `->storageWidget()`), and it is the third place the standalone settings pattern has to be honoured — config key, plugin setter + accessor, `StandaloneSettings` reader.

Two things about it are easy to get wrong, and one already was:

- **`register()` reads `$this->storageWidgetEnabled`, not `StandaloneSettings::storageWidget()`.** The reader resolves the *panel's* plugin, which is not yet this one while `register()` runs, so a fluent `->storageWidget()` came back as silence and fell through to config. The `registers*()` helpers beside it have the same `$this->x ?? config(...)` shape for the same reason. `canView()` does use the reader — by then the plugin is the panel's.
- **It never creates a root.** `Support\StandaloneAccess::scope()` is the read-only counterpart of `granted()` and returns null when the scope has no root yet, rather than 0: a dashboard renders for every authenticated user, exactly like the navigation, and `canAccess()` creating a folder there is the bug that made `ResolvesExistingRoot` exist.

It reuses `Quota::state()` for the bar rather than recomputing the thresholds — the sidebar already owns 85% and "full" — and falls back to `usedBytes()` when there is no cap. `Quota::fileCount()` sits beside `usedBytes()` so the media-scoping predicate (every collection whose bytes are the scope's problem — live, trash and versions, whole subtree — through the one `collections()` list, and the whole subtree) stays in one class.

## Root folder creation

`canAccess()` must never create anything. Filament calls it on every navigation render, so resolving the root the creating way wrote a folder each time a menu was drawn, for every authenticated user, including the ones the check was about to deny. `Contracts\ResolvesExistingRoot` is the read-only escape hatch, and it is a **separate interface on purpose**: adding a method to `FileExplorerRootResolver` would break every resolver a host app has already written. `Support\StandaloneAccess` is the one place both standalone pages take that decision — it authorizes with `0` when the scope has no root yet, and mount() creates it. `ScopeRoots` uses the same read-only path: serving a media file must never create a folder.

`StandaloneAccess` also distinguishes the two ways a resolver fails. An `HttpExceptionInterface` (the per-user and per-tenant resolvers abort without a user or tenant) is a plain "not for this request" and stays quiet; anything else is `report()`ed, because the only symptom otherwise is a page missing from the menu.

`FileExplorerManager::ensureRoot()` is the only idempotent path. The folders table has `unique(['parent_id', 'slug'])`, but MySQL does not dedupe rows with `parent_id IS NULL`, so concurrent first hits could each create a root. A `Cache::lock` closes that window and falls through to the unlocked path if the cache store cannot lock. Use `ensureRoot()` rather than `createRoot()` for anything resolver-driven.

## Demo content

`file-explorer:demo` ([src/Commands/DemoCommand.php](src/Commands/DemoCommand.php) + [Commands/Concerns/GeneratesDemoFiles.php](src/Commands/Concerns/GeneratesDemoFiles.php)) fills a scope with folders and files. It exists because every feature the explorer has needs *content* to show itself, and hand-building that through the UI gives a different library every time.

Four decisions carry the weight:

- **The bytes are real, and honest about what they are.** The kind filter and the type sort both match on `mime_type`, which Media Library sniffs from the file — so a `.mp4` full of noise sniffs as `application/octet-stream` and the Video filter finds nothing, which reads as a bug the package does not have. Every recipe in the trait was checked against `finfo`. That is also why the office files are **OpenDocument and not OOXML**: finfo recognises ODF from its `mimetype` entry (first, stored uncompressed), while a hand-built `.docx` sniffs as `application/zip` and lands under Archive. WebM is absent for the same reason — an EBML header alone does not sniff. And the sizes are real because `UploadedFile::fake()->create()` writes an empty file, which is exactly the trap the test suite already documents: it would leave the quota bar and the storage widget at zero, the two things a demo is for.
- **It goes through `addMedia()` on the folder** — the same call `updatedFiles()` makes, with the same custom properties, the same `FileNames` clash handling and the same `CouldNotLoadImage` catch. A second write path would drift from the first, and a demo is exactly where nobody would notice.
- **It passes the same quota gate.** `Quota::reserve()` per file, and it stops and reports rather than filling the scope past its cap — otherwise the first thing anyone tries after seeing the demo, uploading a file, is refused.
- **The seed is the whole story.** `mt_srand()` once, and every choice below draws from it, which is what makes a screenshot or a bug report reproducible. That is why folder slugs are suffixed the way the component's own "new folder" suffixes them (numeric, on collision) rather than with `Str::random`: a random slug would dodge the `unique(parent_id, slug)` index on a second run just as well, and would silently break the promise.

`--root` deliberately **requires** `--scope`: tags are per scope, and a folder cannot name the scope it is browsed in — a record-scoped key is built from the model and the record. Guessing would tag into a vocabulary the explorer never offers. And the command prints which resolver answered, because the panel's fluent settings are invisible from the console (no panel, so `StandaloneSettings` falls back to config) — a host that called `->scopeKey()` instead of setting the config key would otherwise have its demo land in a root the panel never opens, with nothing on screen to say so.

Note when running it by hand under Testbench: publishing Media Library's migration into `vendor/orchestra/testbench-core/laravel/database/migrations/` breaks the **whole** suite, which creates that table inline in `defineDatabaseMigrations()`. Delete it again afterwards.

## Generators and stubs

`stubs/*.stub` stay inside the package and are read by `MakePageCommand` / `MakeAuthorizerCommand` / `MakeFolderMigrationCommand` via [Commands/Concerns/CopiesPackageStubs.php](src/Commands/Concerns/CopiesPackageStubs.php), which prefers `base_path('stubs/filament-file-explorer/…')` when the host app has published them. `/stubs/filament-file-explorer/` is gitignored (local QA output) while `stubs/` itself is tracked — don't "clean up" either. Changing an abstract page class means checking the matching stub still compiles against it.

## Tests

Pest + Orchestra Testbench, `RefreshDatabase` on sqlite `:memory:`. [tests/TestCase.php](tests/TestCase.php) lists Filament's providers manually and **provider order matters**: `Filament\Support\SupportServiceProvider` must register before `LivewireServiceProvider` (Support binds Livewire's `DataStore` non-shared; Livewire re-binds it shared). Reversing them hands every component a fresh WeakMap and breaks Livewire's error bag on render. The suite also creates the `users`, `projects` and `media` tables inline in `defineDatabaseMigrations()` — Media Library's own migration is not published here.

Blade's compiled views live in `vendor/orchestra/testbench-core/laravel/storage/framework/views/` and survive between runs. Editing a template and re-running in the same second can serve the stale copy, which looks exactly like a change that did not take — delete them if a view assertion disagrees with the file in front of you.

`UploadedFile::fake()->create()` fakes `getSize()` but writes an **empty** file, so Media Library stores a size of 0 — useless for anything measuring bytes (the quota tests use `createWithContent()` instead, with a `%PDF-1.4` header so the mime guess still passes the upload rules).

Fixtures live in [tests/Fixtures/](tests/Fixtures/): `TestPanelProvider` boots a panel with the plugin, `Project` + `ProjectExplorerPage` cover the record-scoped mode.

## Conventions

- `declare(strict_types=1);` in every PHP file (enforced by `pint.json`).
- User-facing strings go through `__('filament-file-explorer::file-explorer.…')` with entries in both `resources/lang/en` and `resources/lang/fr`.
- Non-obvious decisions are documented as short comments explaining *why* (see the provider-order and resolver-binding comments) — match that style rather than describing what the code does.
- The finder is rendered `dir="ltr"` on purpose (the layout, the drag geometry and the marquee all assume it), so **`rtl:` variants can never match** — there were none left after the last one was removed, and adding one is dead code until the whole layout is made direction-aware.
- Host apps must add `@source '…/vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';` to their Filament theme, so Tailwind classes used in Blade must be literal, not built from variables.
- **The finder is bounded, and that is what makes its overflow mean anything.** `--fe-max-h` (70vh) caps `.fe-body`, the flex row holding the sidebar, the main column and the inspector; `.fe-in-modal` lowers it to 60vh for the picker. The items container has always carried `overflow-y`, and it was **inert** — nothing bounded the height, so the container grew with its content and the page scrolled instead. The chain has **two halves, and both live in the package stylesheet** — `min-height: 0` on `.fe-main` / `.fe-sidebar` / `.fe-inspector` / `.fe-trash-list` / `[data-fe-items]`, and `flex-shrink: 0` on the three headers plus `.fe-list-head`. A flex item's `min-height` is `auto`, which means "at least my content", so a link without the floor grows past the bound — and flex then takes the difference out of whatever *can* shrink instead. That was the toolbar: it has a height but no refusal to shrink, so a folder with enough items to scroll squashed it and a folder without did not.

**The inspector's body is the second scroller**, and it needed both halves too. It had the floor and not the refusal, so its panels were squashed instead of scrolling — and `.fe-inspector-meta` carried `overflow: hidden`, which turned that into a clean cut: the description list simply stopped short of its own last rows. Hence `.fe-inspector-body` (`min-height: 0`, `> *` `flex-shrink: 0`) and no clip on the box, whose outer rows round their own corners instead. Removing the clip alone would only have moved the symptom — the rows would have spilled outside the bordered box.

They are declared in `resources/css/file-explorer.css` and **not** as `min-h-0` utilities in the Blade, deliberately: this file ships directly, while a utility class only exists once the host app rebuilds its own theme. A chain built from utilities works in the package's own tests and breaks in a host that has not rebuilt — which is the worst way for a layout rule to break. That is why `.fe-browser`'s `min-height` is `0` and not the 500px it used to be — a floor there is a floor the scrolling child cannot get under, and the row's own 560px floor already gives an empty explorer its presence. A test lists the whole chain, because one missing `min-h-0` breaks it silently.
- **The row views' column header is pinned, and pinning it has three requirements.** `.fe-list-head` is `position: sticky` inside the scrolling items container, so it needs an **opaque** background (at 0.95 alpha the rows sliding under it showed through, which made it read as one more row), a **negative inline margin** cancelling the container's `p-2` so the bar reaches the edges instead of floating, and a **box-shadow** rather than a border for the hairline, since a border would add height to a header that has to keep its own. Its inline padding is owned by the stylesheet, not by a `px-3` utility: the header's content box has to come out at the container width less `2.5rem`, exactly like a row's `px-3` inside the container's `p-2`, or the columns stop lining up — and two single-class selectors setting padding is a fight decided by which stylesheet loaded second. A test asserts no `px-` utility comes back to it.
- **The three headers across the top share one height**, `--fe-header-h`, applied to `.fe-head` (the sidebar's), `.fe-toolbar` and `.fe-inspector-head` with `box-sizing: border-box` so the 1px bottom border is inside the number and the borders actually meet. They were each sized by their own padding around their own content — `py-2` around a 20px icon, `py-1.5` around a 32px tool button, `py-2.5` around a line of text — and came out at 37, 45 and 53 pixels, which reads as three panels that do not belong to the same window. The padding on them is horizontal only, and a test asserts no `py-`/`pt-`/`pb-` utility comes back: with a fixed height it would only squeeze the content, so nothing would look broken until the day the height changed.
- **The accent lives in `:root` as `--fe-accent` / `--fe-accent-solid` / `--fe-accent-text` / `--fe-accent-on-solid`, and nowhere else.** It was hardcoded teal in twenty-one CSS declarations and five Blade utilities, which is why the explorer read as a Filament panel wearing a Finder layout. `--fe-accent` is an **rgb triple** because most uses are `rgba(var(--fe-accent), α)` tints — a selection sits over a thumbnail and has to let it through. A Tailwind utility cannot read a custom property, so an accent colour in a template is by definition a second definition of it: `.fe-menu-hint` and `.fe-newfolder-row` exist for exactly that reason, and a test asserts `teal-` never comes back to the views. Deliberately **not** Filament's `--primary-*`: those are the panel's, and this is a file browser's selection colour. The quota bar still uses `bg-primary-500`, because a quota is the application's business rather than the finder's.
- Selection is drawn with the accent; **grey means the trail**, not the selection — `.fe-column__row.is-path` against `.fe-column__row.is-selected`, which is the distinction the Finder makes. The one solid fill is `.fe-label--selected`: the icon view's label pill is solid accent with white text, because that is the cue the Finder makes unmistakable, while the well behind the icon stays translucent since a thumbnail is under it.
- The toolbar is `flex-wrap: nowrap` in a column that shrinks by 300px when the inspector opens, so **something in it has to give**: the selection count truncates and the search narrows (`min-w-0`, and `min-width: 0` on the input, which otherwise keeps an intrinsic width). Putting `shrink-0` back on `.fe-toolbar__end` makes the toolbar overflow its column, and since the search sits in a `position: relative` wrapper, what overflows paints *over* the inspector instead of under it.

## Documentation

[README.md](README.md) is deliberately short — install, the two modes, a feature table linking out, license. The reference lives in [docs/](docs/), a Jekyll site GitHub Pages builds from the `docs/` directory on `main`: no `package.json` and no build step, the same stance the package takes for its own assets.

Two things about it are load-bearing:

- **[docs/_data/nav.yml](docs/_data/nav.yml) is the only definition of the sidebar**, and the prev/next links in [docs/_layouts/doc.html](docs/_layouts/doc.html) walk the same flat list built from it. A page added to the tree and not to that file is unreachable; one in that file with no page 404s. Nothing else needs updating.
- **The stylesheet is hand-written rather than a remote theme.** A `remote_theme` is a version to drift, and the accent here is `--fe-accent`'s own value so the docs look like the thing they document.

Pages carry only `title:` and `description:` in their front matter; the H1 is in the markdown. Internal links are relative and end in `.md` — `jekyll-relative-links` rewrites them, which is what makes a page read correctly on GitHub *and* on the site. Anchors follow kramdown's rule (punctuation dropped, spaces to hyphens), so `` `file-explorer:demo` `` is `#file-explorerdemo` and not `#file-explorer-demo`.

There is no link checker in CI. `docs/` has 29 pages and the cross-references are dense, so verify with a script over the tree — front matter present, every page in `nav.yml` and every `nav.yml` url a page, every relative link and anchor resolving — rather than by reading.

## Foreign agent configs

`~/.codex/config.toml` and `~/.gemini/settings.json` exist on this machine. If you want their MCP servers, slash commands, subagents, skills, or instructions brought into Claude Code, reply `/import` to scan and list what's importable, then `/import --yes=<digest>` (the scan output names the digest) to apply the user-level items.
