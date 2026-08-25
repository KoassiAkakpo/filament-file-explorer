---
title: Installation
description: Install the package, run the migrations, register the plugin and publish the assets.
---

# Installation

```bash
composer require koassi/filament-file-explorer
```

## Migrations

The package brings its own migrations — the folder tree, shares, annotations and file versions. Media Library's have to be published separately, as they always do:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan migrate
```

Then publish the config:

```bash
php artisan filament-file-explorer:install
```

The install command also prints anything the host is missing for the features that need tooling — a thumbnail kind whose generator is not installed, say. It is safe to re-run.

## Register the plugin

```php
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(FilamentFileExplorerPlugin::make());
}
```

An **Explorer** entry appears in the navigation at `/{panel}/file-explorer`, and the root folder is created on the first real visit. See [the standalone page](standalone-page.md) for what you can change about it.

## Tailwind and assets

The explorer's views use Tailwind classes, and your Filament theme is what compiles them — so it has to be told to look at the package's Blade files:

```css
/* resources/css/filament/admin/theme.css */
@source '../../../../vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';
```

Adjust the number of `../` to your own theme's depth. Then:

```bash
npm run build
php artisan filament:assets
```

`filament:assets` publishes the package's JavaScript and CSS. There is **no build step** for them — they are shipped as sources and published straight from there, so nothing needs regenerating. Re-run it after upgrading, as you would for any Filament plugin.

> Forgetting the `@source` line is the failure that looks like a broken layout: the explorer renders, but half its spacing and colours are missing because those classes were never compiled into your theme.

## Something to look at

A new install is an empty folder, which shows almost nothing about itself — the kind filter has no kinds to offer, the size sort nothing to order, the thumbnails no images. One command fills it:

```bash
php artisan file-explorer:demo
```

Real files, of every kind, with a seed it prints so you can get the same library back. See [Commands](../reference/commands.md#file-explorerdemo).

## Verify

| | |
| --- | --- |
| The navigation shows **Explorer** | The plugin is registered and `browse` is allowed |
| The page renders with correct spacing | Your theme picked up the `@source` line |
| A file uploads and shows a thumbnail | Media Library's disk is writable, GD or Imagick is there |
| Drag, arrows and shift-click select | `filament:assets` was run after the last upgrade |

## Next

- [The standalone page](standalone-page.md) — slugs, navigation, quotas, and the config-vs-plugin precedence rule
- [Choosing the root folder](root-resolvers.md) — one library, or one per user or tenant
- [Authorization](../integrating/authorization.md) — the explorer ships permissive; this is how you close it down
