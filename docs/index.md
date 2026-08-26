---
title: Overview
description: A Finder-style file explorer for Filament v4/v5, backed by Spatie Media Library over a folder tree.
---

# Filament File Explorer

A Finder-style file explorer for Filament v4/v5, backed by [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary) over a folder tree. Icons, columns and details views; keyboard operation; drag and drop; trash, quotas, tags, versions and share links.

![The explorer browsing a photo folder in dark mode](https://koassiakakpo.github.io/filament-file-explorer/assets/screenshots/overview-dark.webp)

<div class="badges" markdown="1">

[![Latest version](https://img.shields.io/packagist/v/koassi/filament-file-explorer.svg?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![Tests](https://img.shields.io/github/actions/workflow/status/KoassiAkakpo/filament-file-explorer/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/KoassiAkakpo/filament-file-explorer/actions/workflows/tests.yml)
[![Downloads](https://img.shields.io/packagist/dt/koassi/filament-file-explorer.svg?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![License](https://img.shields.io/packagist/l/koassi/filament-file-explorer.svg?style=flat-square)](https://github.com/KoassiAkakpo/filament-file-explorer/blob/main/LICENSE)

</div>

```bash
composer require koassi/filament-file-explorer
php artisan filament-file-explorer:install
```

```php
$panel->plugin(FilamentFileExplorerPlugin::make());
```

That is a working explorer at `/{panel}/file-explorer`. [Installation](getting-started/installation.md) has the rest of the steps.

## It works two ways

**A standalone page** *(the default)* — a panel-level page in the navigation menu, backed by a single root folder. No model, no record. Where the root comes from is a [resolver's](getting-started/root-resolvers.md) decision: one shared library, one per user, one per tenant, or your own.

**Record-scoped pages** — an explorer attached to an Eloquent record and reachable from its resource, so a project or a client has files of its own. [Record-scoped pages](getting-started/record-scoped-pages.md).

Both reduce to the same Livewire component driven by two values: a **scope key** (authorization and session namespace) and a **root folder id**. Everything else in the package is built on that pair, and the two modes differ only in where they come from.

## What is in it

<ul class="cards" markdown="0">
<li><a href="browsing/views/"><strong>Three views</strong><span>Icons, the Finder's cascading columns, and details rows — remembered per scope.</span></a></li>
<li><a href="browsing/large-libraries/"><strong>Large libraries</strong><span>Sorting, filtering and windowing in SQL, so thousands of files stay responsive.</span></a></li>
<li><a href="browsing/keyboard-and-touch/"><strong>Keyboard and touch</strong><span>The listing is a listbox. A finger gets its own gestures rather than the mouse's.</span></a></li>
<li><a href="files/large-uploads/"><strong>Uploads of any size</strong><span>Sliced past <code>post_max_size</code>, or straight to S3 without touching the server.</span></a></li>
<li><a href="files/trash/"><strong>Trash</strong><span>Deleting moves aside instead of destroying, with restore and purge.</span></a></li>
<li><a href="files/versions/"><strong>File versions</strong><span>Replacing a file keeps the last N, restorable from the inspector.</span></a></li>
<li><a href="organising/tags-and-descriptions/"><strong>Tags and descriptions</strong><span>A per-scope vocabulary, searchable, and a filter that looks through the subtree.</span></a></li>
<li><a href="files/share-links/"><strong>Share links</strong><span>A public link to one file, expiring, revocable, dying on its own when the file moves.</span></a></li>
<li><a href="files/quotas/"><strong>Quotas</strong><span>A cap per scope, shown in the sidebar and optionally on the dashboard.</span></a></li>
<li><a href="integrating/picker/"><strong>A form field</strong><span>The explorer in a modal, writing media ids into your form's state.</span></a></li>
<li><a href="integrating/authorization/"><strong>Authorization</strong><span>Two independent guards on every entry point, and an ability set that can grow.</span></a></li>
<li><a href="integrating/events/"><strong>Twenty events</strong><span>Every mutation announces itself, so audit, scan and index need no fork.</span></a></li>
</ul>

## Two guards, on every entry point

Worth knowing before anything else, because it is the shape of the whole package:

- **An ability check.** Your `FileExplorerAuthorizer` answers what a scope may do. Nothing ever defaults to allowed, and an ability nobody declared is refused.
- **A containment check.** Folder and media ids arrive as user input — Livewire parameters, query strings, route bindings — so every one of them is walked up the tree to prove it belongs to the root currently being browsed.

Both are required, on every action and every route. [Authorization](integrating/authorization.md) covers what that means for your own code.

## Requirements

| | |
| --- | --- |
| PHP | 8.2+ |
| Laravel | 11 or 12 |
| Filament | v4 or v5 |
| Media Library | v11 |

The test suite needs PHP 8.3, since Pest and Testbench do — the package itself runs on 8.2.

## Where to go next

New to it: [Installation](getting-started/installation.md), then [the standalone page](getting-started/standalone-page.md).

Coming from an earlier version: [Upgrading](getting-started/upgrading.md).

Looking for a setting: [Configuration reference](reference/configuration.md).
