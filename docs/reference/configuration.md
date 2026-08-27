---
title: Configuration
description: Every key in config/filament-file-explorer.php, and which of them the plugin can override.
---

# Configuration

```bash
php artisan filament-file-explorer:install
```

publishes `config/filament-file-explorer.php`.

## Config or plugin

Anything in the `standalone` block, plus `quota`, `refresh`, `view.default` and `folders.max_depth`, can also be set **on the plugin** — and the plugin wins. That keeps settings panel-scoped in a multi-panel application instead of mutating global config, and keeps things working outside a panel (console, tests) where there is no plugin to ask.

Marked **P** below where a plugin setter exists. See [The standalone page](../getting-started/standalone-page.md#config-or-plugin).

Two settings are **config only, and deliberately**: `thumbnails.*` and `folders.model`. Both are read by the model, which knows nothing about the panel it is being browsed from.

## Top level

| Key | Default | |
| --- | --- | --- |
| `authorizer` | `AllowAllAuthorizer` | **P** — your [`FileExplorerAuthorizer`](../integrating/authorization.md) |
| `collection` | `'file-explorer'` | The media collection [files live in](../files/where-files-land.md#the-collection) |
| `auto_create_root` | `true` | Create a root folder when a `HasFileExplorer` model is created — and on the first visit to a record that has none, see [Records that already exist](../getting-started/record-scoped-pages.md#records-that-already-exist) |
| `morph_class` | `null` | Only when [migrating from another implementation](../integrating/folder-model.md) |
| `livewire_component` | `Livewire\FileExplorer` | The component the pages mount |

## `routes`

The media routes — download, preview, thumbnail, archive.

| Key | Default |
| --- | --- |
| `prefix` | `'file-explorer/media'` |
| `middleware` | `['web', 'auth']` |
| `name` | `'filament-file-explorer.media.'` |

The route **names** are frozen at 1.0.0. They never trust the scope key in their own URL — see [Authorization](../integrating/authorization.md#the-media-routes).

## `upload`

| Key | Default | |
| --- | --- | --- |
| `max_size_kb` | `51200` | A promise, not a ceiling — read [Large uploads](../files/large-uploads.md) |
| `allowed_extensions` | every kind the icons render | SVG deliberately absent |
| `allowed_mime_types` | matching list | Checked against the **sniffed** type |
| `on_conflict` | `'rename'` | `rename`, `replace` or `skip` — see [Uploads](../files/uploads.md#when-the-name-is-taken) |
| `chunk.enabled` | `true` | [Slicing](../files/large-uploads.md#slicing) |
| `chunk.size_kb` | `4096` | An upper bound; sized down to fit `post_max_size`, floored at 256 KB |
| `chunk.ttl_minutes` | `60` | How long an interrupted upload's bytes are kept |
| `chunk.directory` | `'file-explorer-chunks'` | Its own, never Livewire's |
| `chunk.routes.*` | `['web', 'auth']` | Registered on the **setting**, not on whether slicing engages |

## `listing`

| Key | Default | |
| --- | --- | --- |
| `per_page` | `100` | Items per window; "Load more" raises it by this again |

## `thumbnails`

Config only.

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | |
| `width` / `height` | `320` | |
| `queued` | `false` | A host with no worker would never get one |
| `kinds` | `['image']` | `pdf` and `video` need tooling — [Thumbnails](../files/thumbnails.md#pdfs-and-videos) |
| `pdf_page` | `1` | |
| `video_second` | `1` | Not 0: many videos open on black |

## `quota`

| Key | Default | |
| --- | --- | --- |
| `bytes` | `null` | **P** — per root folder, not per application. [Quotas](../files/quotas.md) |

`->quota(null)` lifts a limit config had set: null means *no limit*, not *no opinion*.

## `refresh`

| Key | Default | |
| --- | --- | --- |
| `seconds` | `null` | **P** — floored at 5. [Live updates](../browsing/live-updates.md) |

## `share`

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | [Share links](../files/share-links.md) |
| `table` | `file_explorer_shares` | |
| `default_ttl_days` | `7` | `null` for links that never expire by default |
| `max_ttl_days` | `30` | A **ceiling**; `null` allows non-expiring links |
| `routes.middleware` | `['web']` | Without `auth`, deliberately |

## `annotations`

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | [Tags and descriptions](../organising/tags-and-descriptions.md) |
| `descriptions_table` | `file_explorer_descriptions` | |
| `tags_table` | `file_explorer_tags` | |
| `tag_items_table` | `file_explorer_tag_items` | |

## `trash`

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | `false` deletes permanently. [Trash](../files/trash.md) |
| `collection` | `file-explorer-trash` | Must not equal the live collection |

## `versions`

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | Only engages on `on_conflict => 'replace'`. [File versions](../files/versions.md) |
| `collection` | `file-explorer-versions` | Must equal neither the live nor the trash collection |
| `keep` | `3` | Minimum 1 |
| `table` | `file_explorer_file_versions` | |

## `folders`

| Key | Default | |
| --- | --- | --- |
| `max_depth` | `12` | **P** — bounds creation, uploads, copies and the column panes |
| `table` | `file_explorer_folders` | |
| `model` | `Models\Folder` | Config only. [Your own folder model](../integrating/folder-model.md) |

## `view`

| Key | Default | |
| --- | --- | --- |
| `default` | `'grid'` | **P** — `grid`, `columns` or `details`. Until the user picks |

## `standalone`

All **P**. See [The standalone page](../getting-started/standalone-page.md).

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | Master switch |
| `register_pages` | `true` | Off to register the page classes yourself |
| `files_page` | `true` | The flat files table |
| `slug` | `'file-explorer'` | Relative to the panel path |
| `files_slug` | `'file-explorer/files'` | |
| `scope_key` | `'library'` | Authorization and session namespace |
| `resolver` | `GlobalRootResolver` | [Choosing the root folder](../getting-started/root-resolvers.md) |
| `root.name` / `root.slug` | `Library` / `library` | Created on first visit |
| `storage_widget` | `false` | The dashboard widget |
| `navigation.enabled` | `true` | |
| `navigation.files_enabled` | `false` | |
| `navigation.label` | `null` | |
| `navigation.icon` | `heroicon-o-folder` | |
| `navigation.group` | `null` | |
| `navigation.sort` | `null` | |

## Settings that live elsewhere

Worth knowing where to look, because these decide things people expect to find here:

| | Where | |
| --- | --- | --- |
| The disk files go to | `media-library.disk_name` | [Where files land](../files/where-files-land.md) |
| The path layout | `media-library.custom_path_generators` | Same page |
| The real per-file ceiling | `media-library.max_file_size` | [Large uploads](../files/large-uploads.md) |
| Direct-to-storage | `livewire.temporary_file_upload.disk` | Same page |
| The temporary upload rules | `livewire.temporary_file_upload.rules` | Same page |
| Thumbnail generators | `media-library.image_generators` | [Thumbnails](../files/thumbnails.md#your-own-generator) |
| The ffmpeg binary | `media-library.ffmpeg_path` | Same page |
| The accent colour, the height | your own theme's CSS | [Theming](theming.md) |

## Adding a standalone setting

If you are contributing one, it goes in **three** places or it does not work:

1. the config file,
2. a fluent setter plus an accessor on `FilamentFileExplorerPlugin`,
3. a reader on `Support\StandaloneSettings` — the single reader, never `config()` at a call site.

And if its "unset" value is a legal value — a nullable number, say — it needs its own `has*()` flag beside the getter, or the plugin cannot tell *no opinion* from *no limit*.
