# Filament File Explorer

[![Latest version](https://img.shields.io/packagist/v/koassi/filament-file-explorer.svg?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![Tests](https://img.shields.io/github/actions/workflow/status/KoassiAkakpo/filament-file-explorer/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/KoassiAkakpo/filament-file-explorer/actions/workflows/tests.yml)
[![Downloads](https://img.shields.io/packagist/dt/koassi/filament-file-explorer.svg?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![PHP](https://img.shields.io/packagist/dependency-v/koassi/filament-file-explorer/php?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![License](https://img.shields.io/packagist/l/koassi/filament-file-explorer.svg?style=flat-square)](LICENSE)

A Finder-style file explorer for Filament v4/v5, backed by [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary) over a folder tree. Icons, columns and details views; keyboard operation; drag and drop; trash, quotas, tags, versions and share links.

**📖 [Documentation](https://koassiakakpo.github.io/filament-file-explorer/)**

## Install

```bash
composer require koassi/filament-file-explorer

php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan migrate

php artisan filament-file-explorer:install
```

Register the plugin in your panel provider:

```php
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(FilamentFileExplorerPlugin::make());
}
```

Add the package views to your Filament theme so Tailwind picks up their classes, then publish the assets:

```css
@source '../../../../vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';
```

```bash
npm run build
php artisan filament:assets
```

An **Explorer** entry appears in the navigation at `/{panel}/file-explorer`, and the root folder is created on first visit. Full steps: [Installation](https://koassiakakpo.github.io/filament-file-explorer/getting-started/installation/).

```bash
php artisan file-explorer:demo      # fill it with something to look at
```

## It works two ways

**A standalone page** *(default)* — a panel-level page in the navigation menu, backed by a single root folder. No model, no record. Where the root comes from is a resolver's decision: one shared library, one per user, one per tenant, or your own.

**Record-scoped pages** — an explorer attached to an Eloquent record and reachable from its resource, so a project or a client has files of its own.

Both reduce to the same Livewire component driven by two values: a **scope key** and a **root folder id**.

## What is in it

| | |
| --- | --- |
| [Views](https://koassiakakpo.github.io/filament-file-explorer/browsing/views/) | Icons, the Finder's cascading columns, details rows — remembered per scope |
| [Large libraries](https://koassiakakpo.github.io/filament-file-explorer/browsing/large-libraries/) | Sorting, filtering and windowing in SQL; thousands of files stay responsive |
| [Keyboard and touch](https://koassiakakpo.github.io/filament-file-explorer/browsing/keyboard-and-touch/) | The listing is a listbox; a finger gets its own gestures |
| [Large uploads](https://koassiakakpo.github.io/filament-file-explorer/files/large-uploads/) | Sliced past `post_max_size`, or straight to S3 |
| [Trash](https://koassiakakpo.github.io/filament-file-explorer/files/trash/) | Deleting moves aside, with restore and purge |
| [File versions](https://koassiakakpo.github.io/filament-file-explorer/files/versions/) | Replacing a file keeps the last N |
| [Tags and descriptions](https://koassiakakpo.github.io/filament-file-explorer/organising/tags-and-descriptions/) | A per-scope vocabulary, searchable, filterable through the subtree |
| [Share links](https://koassiakakpo.github.io/filament-file-explorer/files/share-links/) | A public link to one file — expiring, revocable |
| [Quotas](https://koassiakakpo.github.io/filament-file-explorer/files/quotas/) | A cap per scope, in the sidebar and on the dashboard |
| [Thumbnails](https://koassiakakpo.github.io/filament-file-explorer/files/thumbnails/) | Images by default; PDF and video opt-in |
| [The form field](https://koassiakakpo.github.io/filament-file-explorer/integrating/picker/) | The explorer in a modal, writing media ids into your form state |
| [Events](https://koassiakakpo.github.io/filament-file-explorer/integrating/events/) | Twenty of them, so audit, scan and index need no fork |

## Two guards, on every entry point

- **An ability check.** Your `FileExplorerAuthorizer` answers what a scope may do. Nothing ever defaults to allowed.
- **A containment check.** Folder and media ids arrive as user input, so every one is walked up the tree to prove it belongs to the root being browsed.

Both are required, on every action and every route. See [Authorization](https://koassiakakpo.github.io/filament-file-explorer/integrating/authorization/).

## Requirements

PHP 8.2+ · Laravel 11 or 12 · Filament v4 or v5 · Media Library v11

## Contributing

```bash
composer install
./vendor/bin/pest                     # PHP suite
node --test "tests/Js/*.test.mjs"     # selection and keyboard logic
```

The JavaScript tests need nothing installed: they run the shipped script in a `node:vm` context with a stubbed Alpine and DOM. See [Contributing](https://koassiakakpo.github.io/filament-file-explorer/reference/contributing/).

## License

MIT — see [LICENSE](LICENSE).
