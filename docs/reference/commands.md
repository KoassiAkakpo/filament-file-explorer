---
title: Commands
description: The six artisan commands, and what each is actually for.
---

# Commands

## `filament-file-explorer:install`

```bash
php artisan filament-file-explorer:install [--force] [--stubs] [--migrate]
```

Publishes the config file. Safe to re-run — and worth re-running after an upgrade, because it also **prints what the host is missing** for features that need tooling: a [thumbnail kind](../files/thumbnails.md#pdfs-and-videos) whose generator is not installed, say, which otherwise produces nothing quietly.

It also prints **the largest file this host will actually accept**, with the five stacked ceilings and an arrow on the one that decides — `upload.max_size_kb` reads like the ceiling and usually is not. See [Ask this host what its real ceiling is](../files/large-uploads.md#ask-this-host-what-its-real-ceiling-is).

`--stubs` publishes the generator templates, which is only needed to customise them. `--migrate` runs the migrations after installing.

## `filament-file-explorer:make-page`

```bash
php artisan filament-file-explorer:make-page ProjectResource
php artisan filament-file-explorer:make-page --standalone
```

Generates the explorer page and the files list page — for a resource, or panel-level. `--explorer` or `--list` generates one of the two; `--force` overwrites. Generating one alone is fine — the header button each page offers to the other is drawn only when that other page is actually registered.

The generated classes extend the package's abstract pages, so they inherit the scope resolution and both guards. See [Record-scoped pages](../getting-started/record-scoped-pages.md) and [Replacing the pages](../getting-started/standalone-page.md#replacing-the-pages).

## `filament-file-explorer:make-authorizer`

```bash
php artisan filament-file-explorer:make-authorizer [name] [--force]
```

Writes a `FileExplorerAuthorizer` implementation answering all eleven abilities, with the comments explaining what each one gates. Defaults to `FileExplorerAuthorizer`. See [Authorization](../integrating/authorization.md).

## `filament-file-explorer:make-folder-migration`

```bash
php artisan filament-file-explorer:make-folder-migration projects [--column=folder_id]
```

Generates the migration adding a `folder_id` column to a model's table, for [record-scoped pages](../getting-started/record-scoped-pages.md).

## `file-explorer:purge-trash`

```bash
php artisan file-explorer:purge-trash [--days=30]
```

Permanently deletes what has been in the [trash](../files/trash.md) for at least that long. **Nothing expires on its own** — schedule this if you want it to:

```php
Schedule::command('file-explorer:purge-trash --days=30')->daily();
```

## `file-explorer:demo`

```bash
php artisan file-explorer:demo
```

Fills a scope with folders and files worth looking at. It exists because an empty explorer shows almost nothing about itself: the kind filter has no kinds to offer, the size sort nothing to order, the thumbnails no images, and the trash is a blank panel.

| Option | |
| --- | --- |
| `--files=70` `--folders=14` `--depth=3` | How much, and how deep — capped by `folders.max_depth` |
| `--seed=` | Reuse the seed a previous run printed and get the same library back |
| `--fresh` | Empty the root first, instead of adding to what is there |
| `--user=ID` | Act as that user, so per-user scopes resolve and "Added by" is filled in |
| `--root=ID --scope=KEY` | Fill a folder outright — a record-scoped page's root, say |
| `--force` | Skip the production confirmation |

Four things about it are deliberate:

- **The files are real.** A demo built from faked uploads would report sizes over empty files, which leaves the quota bar and the storage widget at zero and gives the thumbnail conversion nothing to convert. Every kind is generated as genuine bytes whose **sniffed** mime type is the one the [kind filter](../browsing/large-libraries.md#filtering-by-kind) matches on — so the office files are OpenDocument rather than OOXML, because a hand-built `.docx` sniffs as a zip archive and would land under Archive.
- **It writes through the same call an upload makes**, with the same custom properties, the same name-clash handling and the same failure catch. A second write path would drift from the first, and a demo is exactly where nobody would notice.
- **It passes the same [quota](../files/quotas.md) gate**, and stops and reports rather than filling a scope past its cap. Otherwise the first thing anyone tries after seeing the demo — uploading a file — is refused.
- **The seed is the whole story.** One seed drives every choice below it, which is what makes a screenshot or a bug report reproducible.

By default it fills the root the **standalone resolver** hands out — the library the panel's explorer page actually opens — and prints which resolver answered.

### `--root` requires `--scope`

Not pedantry: tags are a scope's own vocabulary, and a folder cannot name the scope it is browsed in. Guessing would tag into a vocabulary the explorer never offers.

For the standalone page it is your configured `scope_key`; for a record-scoped page it is `{model-kebab}.{id}`:

```bash
php artisan file-explorer:demo --root=42 --scope=project.4
```

> **If your panel configures the plugin fluently** — `->scopeKey('docs')` rather than the config key — the console cannot see that. There is no panel in a command, so the reader falls back to config. Pass `--root` and `--scope` explicitly in that case. The command prints the scope and root it used, so a mismatch is visible rather than mysterious.

## Media Library's commands, which you will want

```bash
# Fill in thumbnails for files uploaded before thumbnails existed, or before
# you enabled a new kind.
php artisan media-library:regenerate "Koassi\FilamentFileExplorer\Models\Folder" \
    --only=thumbnail --only-missing
```
