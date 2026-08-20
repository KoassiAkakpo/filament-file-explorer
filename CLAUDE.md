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
```

`larastan`/`phpstan` are dev dependencies but there is **no `phpstan.neon`** — static analysis is not wired up. Running `./vendor/bin/pint` on the whole repo currently reports pre-existing style drift in several files (import order, strict-type declarations in `config/` and `resources/lang/`); scope formatting to the files you touch rather than reformatting the tree.

There is no `package.json` and no JS/CSS build step. `resources/dist/file-explorer.{js,css}` are byte-identical **manual copies** of `resources/{js,css}/file-explorer.{js,css}` — the `dist` files are what `FilamentAsset::register()` ships, so after editing a source asset you must copy it into `resources/dist/` or the change never reaches host apps.

## Two operating modes

Everything in the package reduces to one Livewire component driven by two values: a **scope key** (authorization + session namespace) and a **root folder id**. The two modes only differ in where those come from.

1. **Standalone (panel-level)** — `FilamentFileExplorerPlugin` registers `Filament\Pages\FileExplorer` and `FileExplorerFiles` on the panel. Scope key and root folder come from a `FileExplorerRootResolver` (`GlobalRootResolver` / `PerUserRootResolver` / `PerTenantRootResolver`). No Eloquent record involved.
2. **Record-scoped** — abstract `Pages\FileExplorerPage` / `Pages\FileExplorerFilesPage` are extended by app classes generated from `stubs/` and registered in a resource's `getPages()`. Scope key is `{model-kebab}.{id}`, root folder is the record's `folder_id`, via the `HasFileExplorer` model trait.

Both share [Pages/Concerns/InteractsWithFileExplorer.php](src/Pages/Concerns/InteractsWithFileExplorer.php), which is the single seam: it declares `fileExplorerScopeKey()` + `resolveFileExplorerRootFolderId()` abstract, sets `$rootFolderId`, and aborts 403 via the authorizer. The page Blade view just hands both values to `<livewire:filament-file-explorer::file-explorer>`. Adding a third mode means implementing those two methods, nothing more.

## Configuration precedence (important)

Standalone settings are read through [Support/StandaloneSettings.php](src/Support/StandaloneSettings.php), never from `config()` directly at call sites. It tries the panel's plugin instance first, then falls back to config. This keeps settings **panel-scoped** in multi-panel apps instead of mutating global config, and keeps things working outside a panel (console, tests) where `FilamentFileExplorerPlugin::get()` throws. Any new standalone setting must be added in three places: the config file, a fluent setter + accessor on the plugin, and a `StandaloneSettings` reader.

## Container bindings

Registered in [FilamentFileExplorerServiceProvider.php](src/FilamentFileExplorerServiceProvider.php):

- `FileExplorerAuthorizer` — singleton resolving `config('filament-file-explorer.authorizer')`; the plugin's `->authorizer()` overwrites that config key in `boot()`.
- `FolderTree` — **`scoped`, not `singleton`**: it memoises containment checks, parent lookups and descendant sets for one request. A shared instance in a long-lived worker (Octane) would keep answering for a tree that has since been reorganised.
- `FileExplorerRootResolver` — deliberately **`bind`, not `singleton`**: the resolver *class* is panel-scoped so it must be looked up per resolution. The three concrete resolvers are singletons, and that is what memoises the root-folder lookup (`canAccess()` runs on every navigation render, so an unmemoised resolver would hit the DB repeatedly and could create duplicate roots).

## Listing and tree walks

The explorer never loads a folder's contents into memory to sort them. [Support/FolderListing.php](src/Support/FolderListing.php) sorts and windows in SQL and returns `{folders, files, shown, total, hasMore}`; `Livewire\FileExplorer::listing()` calls it with a `perPage` window that `loadMore()` grows and `resetListing()` sends back to one page (`listing.per_page` in config). Folders fill the window first, and the file budget is `perPage - totalFolders` so that widening the window only ever appends. Every `ORDER BY` ends with the primary key: without that tiebreak, rows sharing a sort value can swap between windows and "load more" repeats or skips them. Sorting by *type* orders on `mime_type` because extracting an extension is not portable across drivers.

`resetListing()` is the single hook meaning "the listing may have changed" — every mutation and every navigation calls it — and it also flushes `FolderTree`. That flush matters: creating or deleting a folder changes the set of ids under the root, so a request that mutates and then re-renders would otherwise draw a stale sidebar and search the old scope.

## Selection and keyboard

Selection lives in the Alpine `feSel` store ([resources/js/file-explorer.js](resources/js/file-explorer.js)) and is mirrored to the component through a debounced `setSelection`. Two conventions hold it together:

- Every rendered item carries `data-fe-type` (`folder`/`file`) and `data-id`, and the items container carries `data-fe-items`. `feSel.orderedItems(scope)` reads the DOM through those attributes — that is how shift-range and the arrow keys work in the grid *and* the row views without anyone knowing the column count (vertical movement groups items by `offsetTop`). A new view mode has to keep those attributes.
- Keyboard shortcuts are bound on the items container (`@keydown="onKeydown($event)"`), never on the window. A page can hold more than one explorer — the `FileExplorerPicker` in a modal — and a window binding would fire for all of them, including while the user types in the search box.

`feSel.click()` owns the modifier logic (shift extends from the anchor, ctrl/meta toggles) so the Blade files stay declarative, and `toggle()` moves the anchor. `replace()` mirrors server state without syncing back; `select()` is the one that syncs, so UI-driven selection must go through it.

## Security model

Two independent guards, both required — never add an entry point that skips either:

- **Ability check** — `FileExplorerAuthorizer::abilities()` returns a fixed array (`browse`, `search`, `getInfo`, `download`, `upload`, `mkdir`, `rename`, `move`, `copy`, `delete`, `deleteFolder`). Every mutating Livewire action calls `abort_unless($this->ability(...), 403)`. The delete-state methods additionally support time-window rules.
- **Containment check** — `FolderTree::isUnderRoot($folder, $rootFolderId)` walks parents to prove a folder belongs to the current scope. `Livewire\FileExplorer::assertUnderRoot()`, `MediaController`, and `InteractsWithFileExplorerTable::fileExplorerMediaQuery()` all enforce it, because folder ids and media ids arrive as user input (Livewire params, `?folder=` query string, route bindings).

The containment check is only worth anything if `$rootFolderId` is **not** derived from the thing being checked. The media routes take the scope key as a URL segment, so they resolve the root through [Support/ScopeRoots.php](src/Support/ScopeRoots.php) — the standalone resolver when the scope key is the one it owns, otherwise a session registry that pages and the Livewire component write once `canAccess()` has passed. Deriving the root from the requested media (walking up to its top-most ancestor) or reading it from a query parameter proves only that a folder sits under its own ancestor, which is always true. `MediaController` additionally checks `model_type` and `collection_name`: a media row from another model whose `model_id` collides with an in-scope folder id would otherwise pass containment.

Session state (`currentFolderId.{scopeKey}`, `clipboard.{scopeKey}`, `filament-file-explorer.roots.{authId}`) is namespaced by scope key, which is why per-user/per-tenant resolvers include the user or tenant key in the scope key.

## Root folder creation

`FileExplorerManager::ensureRoot()` is the only idempotent path. The folders table has `unique(['parent_id', 'slug'])`, but MySQL does not dedupe rows with `parent_id IS NULL`, so concurrent first hits could each create a root. A `Cache::lock` closes that window and falls through to the unlocked path if the cache store cannot lock. Use `ensureRoot()` rather than `createRoot()` for anything resolver-driven.

## Generators and stubs

`stubs/*.stub` stay inside the package and are read by `MakePageCommand` / `MakeAuthorizerCommand` / `MakeFolderMigrationCommand` via [Commands/Concerns/CopiesPackageStubs.php](src/Commands/Concerns/CopiesPackageStubs.php), which prefers `base_path('stubs/filament-file-explorer/…')` when the host app has published them. `/stubs/filament-file-explorer/` is gitignored (local QA output) while `stubs/` itself is tracked — don't "clean up" either. Changing an abstract page class means checking the matching stub still compiles against it.

## Tests

Pest + Orchestra Testbench, `RefreshDatabase` on sqlite `:memory:`. [tests/TestCase.php](tests/TestCase.php) lists Filament's providers manually and **provider order matters**: `Filament\Support\SupportServiceProvider` must register before `LivewireServiceProvider` (Support binds Livewire's `DataStore` non-shared; Livewire re-binds it shared). Reversing them hands every component a fresh WeakMap and breaks Livewire's error bag on render. The suite also creates the `projects` and `media` tables inline in `defineDatabaseMigrations()` — Media Library's own migration is not published here.

Fixtures live in [tests/Fixtures/](tests/Fixtures/): `TestPanelProvider` boots a panel with the plugin, `Project` + `ProjectExplorerPage` cover the record-scoped mode.

## Conventions

- `declare(strict_types=1);` in every PHP file (enforced by `pint.json`).
- User-facing strings go through `__('filament-file-explorer::file-explorer.…')` with entries in both `resources/lang/en` and `resources/lang/fr`.
- Non-obvious decisions are documented as short comments explaining *why* (see the provider-order and resolver-binding comments) — match that style rather than describing what the code does.
- Host apps must add `@source '…/vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';` to their Filament theme, so Tailwind classes used in Blade must be literal, not built from variables.

## Foreign agent configs

`~/.codex/config.toml` and `~/.gemini/settings.json` exist on this machine. If you want their MCP servers, slash commands, subagents, skills, or instructions brought into Claude Code, reply `/import` to scan and list what's importable, then `/import --yes=<digest>` (the scan output names the digest) to apply the user-level items.
