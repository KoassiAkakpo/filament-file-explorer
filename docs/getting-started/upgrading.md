---
title: Upgrading
description: What each version added, and the one command that covers most of it.
---

# Upgrading

```bash
composer update koassi/filament-file-explorer
php artisan migrate
php artisan filament:assets
```

That is the whole routine. The three steps are there because the package can add a table, and because its JavaScript and CSS are published into your public directory rather than compiled — a stale copy of them is the one upgrade failure with no error message.

## 1.0.0 and the API freeze

`1.0.0` is where the public API stops moving. What it freezes:

- `Contracts\FileExplorerAuthorizer`, **including the key set** its `abilities()` returns
- `Contracts\FileExplorerRootResolver`, and the optional `ProvidesMultipleRoots` / `ResolvesExistingRoot`
- The three shipped resolvers, and the plugin's fluent setters
- `Models\Concerns\HasFileExplorer`, and `Models\Folder` as the tree model
- The abstract pages, and the `stubs/` generated from them
- The config keys, and the [precedence rules](standalone-page.md#config-or-plugin) between config and the plugin
- The media route names, and their scope-key URL segment
- The DOM contract the JavaScript is built on: `data-fe-items`, `data-fe-type`, `data-id`

[ROADMAP.md](https://github.com/KoassiAkakpo/filament-file-explorer/blob/main/ROADMAP.md) records what had to be settled before the freeze, and why each decision went the way it did.

An ability the explorer gains **after** this point is declared as a variation of one that already existed, and inherits it when your authorizer says nothing — so the set can still grow without turning your implementation into a refusal. [Authorization](../integrating/authorization.md#an-ability-set-that-can-grow) has the details.

## Migrations by version

Each one is additive, and `php artisan migrate` is the whole story. Listed so you can tell what a pending migration is for:

| Table or column | Added in | For |
| --- | --- | --- |
| `file_explorer_folders` | 0.1.0 | The tree |
| `deleted_at` on that table | 0.2.0 | [Trash](../files/trash.md) |
| `file_explorer_shares` | 0.3.0 | [Share links](../files/share-links.md) |
| `file_explorer_descriptions`, `file_explorer_tags`, `file_explorer_tag_items` | 0.6.0 | [Tags and descriptions](../organising/tags-and-descriptions.md) |
| `file_explorer_file_versions` | 1.0.0 | [File versions](../files/versions.md) |

Every one of those features can be turned off in config, and nothing then reads or writes its tables. The migration still runs — a table that exists and is empty costs nothing, and having it means turning the feature on later is a config change rather than a deployment.

## Things that changed behaviour

**View modes are three, not five.** `list` and `table` were variations on `details` with a column dropped. A stored preference naming one of them falls back to the default, which is what an unknown mode has always done — nothing to migrate.

**`Columns` means the cascading browser.** The `table` view used to carry that label, which is the Finder's name for something else. It is now labelled **Table**, and **Columns** is the browser. See [Views](../browsing/views.md).

**Replacing a file keeps it.** With `upload.on_conflict` set to `replace`, the old file becomes a version instead of being destroyed, and `FileVersioned` fires where `FileDeleted` used to. If a listener of yours treated that `FileDeleted` as a loss, it now hears the truth instead. The default policy is `rename`, so a default install never took this path. See [File versions](../files/versions.md).

**Uploads can now be larger than one request.** Slicing is on by default but engages only where a file or a batch will not fit in one request, so an upload that already worked takes exactly the path it took before. What it does *not* lift is Media Library's own 10 MB ceiling — raise `media-library.max_file_size` too, or it is the number that decides. See [Large uploads](../files/large-uploads.md).

**A failed thumbnail no longer costs the upload.** The file lands, the event fires, the explorer draws its icon. This was a fix for images before it was a prerequisite for [PDF and video thumbnails](../files/thumbnails.md).

## If the explorer looks wrong after an upgrade

In this order:

1. `php artisan filament:assets` — the JavaScript and CSS in your public directory are the previous version's.
2. `npm run build` — a new view uses a Tailwind class your theme has not compiled. Check the `@source` line is still in your theme.
3. `php artisan migrate` — check for pending migrations.
4. `php artisan view:clear`, `config:clear`.
