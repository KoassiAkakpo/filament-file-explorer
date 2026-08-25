---
title: The standalone page
description: Slugs, navigation, and the behaviour settings — set on the plugin per panel, or in config for the application.
---

# The standalone page

The plugin registers a panel-level explorer page and, optionally, a flat files table beside it. No Eloquent record is involved: the page gets its scope key and root folder from a [resolver](root-resolvers.md).

## Config or plugin

Everything in the `standalone` config block can also be set fluently on the plugin, and **the plugin wins**. That is not a convenience — it is what keeps the settings *panel-scoped*:

```php
FilamentFileExplorerPlugin::make()
    ->slug('library')                       // route: /{panel}/library
    ->scopeKey('shared-library')            // namespace handed to the authorizer
    ->rootFolder('Shared library')          // root folder name
    ->navigationLabel('Library')
    ->navigationIcon('heroicon-o-folder-open')
    ->navigationGroup('Content')
    ->navigationSort(20)
    ->withFilesNavigation()                 // also show the flat files table in the menu
    ->authorizer(\App\Support\FileExplorerAuthorizer::class)
```

An application with an admin panel and a customer panel can give each its own library, its own authorizer and its own quota without either of them mutating global config. Where there is no panel at all — a console command, a test — the reader falls back to config.

> **One consequence worth knowing.** A command has no panel, so a fluent setting is invisible from the console. `php artisan file-explorer:demo` prints the scope and root it used for exactly that reason: if your panel calls `->scopeKey('docs')` rather than setting the config key, pass `--scope` explicitly. See [Commands](../reference/commands.md#file-explorerdemo).

## Behaviour, not just chrome

The same rule covers the settings that change what the explorer does:

```php
FilamentFileExplorerPlugin::make()
    ->quota(10 * 1024 ** 3)                 // bytes this scope may hold
    ->refreshEvery(20)                      // seconds between automatic refreshes
    ->defaultViewMode('columns')            // grid, columns or details
    ->maxFolderDepth(6)
    ->storageWidget()                       // the dashboard widget
    ->tableColumns(fn (array $columns) => Arr::except($columns, ['preview']))
```

`->quota(null)` and `->refreshEvery(null)` mean *no limit* rather than *no opinion*, so they can lift a limit config had set.

`->tableColumns()` takes a closure and so cannot live in a config file — it is plugin-only. The columns arrive keyed by name, so the callback can add, drop or reorder them.

## The full set of switches

| Method | |
| --- | --- |
| `->slug($slug, $filesSlug = null)` | Route slugs, relative to the panel path |
| `->scopeKey($key)` | Authorization and session namespace |
| `->rootFolder($name, $slug = null)` | Name of the root folder created on first visit |
| `->rootResolver($class)` | Where the scope key and root come from — see [resolvers](root-resolvers.md) |
| `->authorizer($class)` | Your `FileExplorerAuthorizer` |
| `->explorerPage($class)` / `->filesPage($class)` | Your own page classes |
| `->quota($bytes)` | Bytes the scope may hold, `null` for no cap |
| `->refreshEvery($seconds)` | Automatic refresh, `null` for none |
| `->defaultViewMode($mode)` | `grid`, `columns` or `details` |
| `->maxFolderDepth($depth)` | How deep the tree may go |
| `->tableColumns($callback)` | Adjust the flat files table's columns |
| `->storageWidget()` / `->withoutStorageWidget()` | The dashboard widget |
| `->navigationLabel()` `->navigationIcon()` `->navigationGroup()` `->navigationSort()` | Menu entry |
| `->withFilesNavigation()` | Also show the files table in the menu |
| `->withoutFilesPage()` | Register the explorer page only |
| `->withoutNavigation()` | Register the pages, hide the menu entries |
| `->withoutPages()` | Register nothing; you register the page classes yourself |
| `->disabled()` | Turn the standalone page off entirely |

## Replacing the pages

The packaged pages are enough for most applications. To customise one — a different heading, an extra widget, your own `canAccess()` — generate it:

```bash
php artisan filament-file-explorer:make-page --standalone
```

then point the plugin at what was generated:

```php
FilamentFileExplorerPlugin::make()
    ->explorerPage(\App\Filament\Pages\FileExplorer::class)
    ->filesPage(\App\Filament\Pages\FileExplorerFiles::class)
```

The generated classes extend the package's abstract pages, so they inherit the scope resolution and both guards. What they add is yours.

## The files table

Beside the explorer, a flat table of every file in the scope — sortable and searchable across folders, which is the one thing a tree cannot do. Off in the navigation by default (`->withFilesNavigation()` shows it), and it obeys the same containment rule as everything else: a row is only listed if its folder sits under the scope's root.

Its columns go through `->tableColumns()`, keyed by name:

```php
->tableColumns(fn (array $columns) => Arr::except($columns, ['preview', 'uploader']))
```

## The dashboard widget

What the standalone scope holds, on the panel's dashboard — the same figures the explorer's sidebar draws, where someone sees them without going to the explorer first. A quota nobody sees until it refuses an upload is a quota that surprises people.

```php
FilamentFileExplorerPlugin::make()->storageWidget()
```

Off by default: a widget appearing on someone's dashboard the day they upgrade would be a surprise, and a dashboard is not the package's. With a quota set it draws the bar and warns at 85%; without one it shows the total and the file count, which is the useful half — a bar with nothing to fill would only invent a limit.

It describes the **standalone** scope only. Record-scoped pages have one root per record, so there is no single figure a dashboard widget could stand for; read `Support\Quota` directly for a record you know. And it hides itself when the scope is refused, when `browse` is denied, and before the scope has a root at all — [it never creates one](root-resolvers.md#never-create-a-root-while-authorizing).
