# Filament File Explorer

Finder-style file explorer for Filament v4/v5, powered by Spatie Media Library.

Works two ways:

- **Standalone page** *(default)* — a panel-level page in the navigation menu, backed by a single root folder. No model, no record.
- **Record-scoped pages** — an explorer attached to an Eloquent record, reachable from its resource.

## Installation

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

Add the package views to your Filament theme so Tailwind picks up their classes:

```css
@source '../../../../vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';
```

```bash
npm run build
php artisan filament:assets
php artisan vendor:publish --tag=filament-file-explorer-assets
```

That's it. An **Explorer** entry appears in the navigation at `/{panel}/file-explorer`; the root folder is created on first visit.

## Configuring the standalone page

Anything in the `standalone` config block can also be set fluently on the plugin, which takes precedence and stays panel-scoped:

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

Behaviour, not just chrome, is panel-scoped too:

```php
FilamentFileExplorerPlugin::make()
    ->quota(10 * 1024 ** 3)                 // bytes a scope may hold
    ->refreshEvery(20)                      // seconds between automatic refreshes
    ->defaultViewMode('list')               // grid, list, table or details
    ->maxFolderDepth(6)
    ->tableColumns(fn (array $columns) => Arr::except($columns, ['preview']))
```

Other switches: `->withoutFilesPage()`, `->withoutNavigation()`, `->withoutPages()`, `->disabled()`.

### Choosing the root folder

The scope key and root folder come from a `FileExplorerRootResolver`. Three ship with the package:

| Resolver | Behaviour |
| --- | --- |
| `GlobalRootResolver` *(default)* | One root folder shared by everyone |
| `PerUserRootResolver` | One root folder per authenticated user |
| `PerTenantRootResolver` | One root folder per Filament tenant (reuses the tenant's own root when it uses `HasFileExplorer`) |

```php
FilamentFileExplorerPlugin::make()->rootResolver(PerUserRootResolver::class)
```

Or write your own:

```php
class TeamRootResolver implements FileExplorerRootResolver
{
    public function scopeKey(): string
    {
        return 'team.'.auth()->user()->current_team_id;
    }

    public function rootFolderId(): int
    {
        return auth()->user()->currentTeam->ensureFileExplorerRoot()->id;
    }
}
```

Bind it as a singleton so the root is resolved once per request.

Two optional companion interfaces are worth implementing:

- **`ResolvesExistingRoot`** adds `existingRootFolderId(): ?int`, which must answer without writing. Filament calls `canAccess()` on every navigation render, so without it the first page of a session creates the root folder — for every user, including the ones about to be denied. The three shipped resolvers implement it. When the scope has no root yet the authorizer is asked with `0`, and the folder is created on the first real visit.
- **`ProvidesMultipleRoots`** adds `roots(): array`, and the sidebar turns into a location switcher:

```php
public function roots(): array
{
    return [
        ['id' => auth()->user()->ensureFileExplorerRoot()->id, 'name' => 'My files'],
        ['id' => $this->sharedRootId(), 'name' => 'Shared'],
    ];
}
```

The roots of one scope share its scope key, so they share one authorization decision and one ability set — roots that must be authorized apart belong in separate scopes. The explorer still browses one root at a time; the current folder and the clipboard are namespaced per root, and each location remembers where it was left. A switch is validated against `roots()`, never against an id from the request.

Anything a resolver throws that is not an abort is reported, so a missing migration or a mistyped class does not just make the page vanish from the menu.

### Replacing the pages

The packaged pages are enough for most apps. To customise them:

```bash
php artisan filament-file-explorer:make-page --standalone
```

then register the generated classes:

```php
FilamentFileExplorerPlugin::make()
    ->explorerPage(\App\Filament\Pages\FileExplorer::class)
    ->filesPage(\App\Filament\Pages\FileExplorerFiles::class)
```

## Record-scoped pages

Attach an explorer to a model instead of the panel.

1. Add a `folder_id` column and the trait:

```bash
php artisan filament-file-explorer:make-folder-migration projects
php artisan migrate
```

```php
class Project extends Model
{
    use \Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;
}
```

2. Generate the pages and register them:

```bash
php artisan filament-file-explorer:make-page ProjectResource
```

```php
public static function getPages(): array
{
    return [
        // ...
        'files' => Pages\ManageProjectFiles::route('/{record}/files'),
        'files-list' => Pages\ListProjectFiles::route('/{record}/files-list'),
    ];
}
```

Both modes coexist: registering the plugin does not interfere with record-scoped pages, and they use separate root folders.

## Large libraries

The explorer renders `listing.per_page` items at a time (100 by default), folders first, with a “Load more” control for the rest. Sorting, filtering and windowing all happen in SQL, so a folder holding thousands of files stays responsive.

```php
// config/filament-file-explorer.php
'listing' => ['per_page' => 100],
```

## Quotas

`quota.bytes` (null by default) caps what a scope may hold. The cap is per root folder, so with the per-user or per-tenant resolver each user or tenant gets an allowance of that size, and the sidebar shows the usage — amber past 85%, red when full.

Uploads and copies that would go over are refused before anything is written, and the reason is reported. Replacing a file only counts the difference. Trashed files still count: they sit on the disk until they are purged, and a trash that stopped counting would be a way over the cap.

## Who added a file

The uploader is recorded on every upload and shown in the inspector and in the files table. It is resolved from the model each time rather than stored as a name, so a renamed account does not leave stale names behind, and memoised per request: a page costs one query per distinct uploader. When the account is gone, the id is still shown.

## Several sessions on the same tree

Nothing is broadcast, so two people browsing the same root see nothing of each other until they navigate. `refresh.seconds` (or `->refreshEvery()`) closes that: off by default, floored at five seconds.

The polling is driven from Alpine rather than `wire:poll`, so it stands down while the tab is hidden, while an item is being dragged, and while the user is renaming, creating a folder, uploading or reading a dialog. A "Load more" survives a refresh. If the folder you are standing in is deleted by someone else, the explorer falls back to the root instead of rendering a folder that is no longer there — with the trash off, a folder *purged* under you leaves Livewire unable to restore the model at all, and the page has to be reloaded.

Mutations also announce themselves to the other explorers on the same page, which is what keeps a `FileExplorerPicker` in a modal in step with the page behind it.

## Trash

Deleting moves things aside instead of destroying them. Folders are soft-deleted, files move to a separate media collection, and the toolbar's trash button lists what is in there with where it came from and when it went — each row restorable or deletable for good.

```php
// config/filament-file-explorer.php
'trash' => [
    'enabled' => true,                       // false deletes permanently, as before
    'collection' => 'file-explorer-trash',
],
```

Restoring a folder brings back what went down with it, but leaves a file you had trashed on its own where you put it. Restore and purge require the same ability as deleting (`delete` for files, `deleteFolder` for folders).

Nothing expires on its own. Schedule the purge if you want it to:

```php
Schedule::command('file-explorer:purge-trash --days=30')->daily();
```

Upgrading an existing install adds a `deleted_at` column to the folders table, so run `php artisan migrate`.

## Keyboard

The listing is a `listbox`: it takes focus, and the items are options.

| Keys | Action |
| --- | --- |
| Arrows | Move the selection (rows and grid) |
| Shift + click / Shift + arrows | Extend the selection to a range |
| Ctrl/Cmd + click | Add or remove one item |
| Ctrl/Cmd + A | Select everything on screen |
| Enter | Open the selected folder, or preview the selected file |
| F2 | Rename |
| Ctrl/Cmd + C / X / V | Copy, cut, paste |
| Delete / Backspace | Delete the selection |
| Escape | Close the context menu, cancel a rename |

Shortcuts only fire while the listing has focus, never while typing in the search or rename field, and each one still goes through its ability check. Downloading a multi-item selection produces a single archive (`selection/zip`, capped at 500 items).

## Preview and confirmation

Double-clicking a file (or pressing Enter, or picking *Open* in the context menu) opens it in a lightbox: images, video, audio, PDFs and text render inline, anything else offers a download. Left and right arrows walk through the files of the current listing. Previewing requires the `download` ability, since it streams the same bytes as a download.

PDFs and text files render in a same-origin `<iframe>`, so an app that sends `X-Frame-Options: DENY` needs `SAMEORIGIN` (or a `frame-ancestors 'self'` CSP) for that part of the preview to show.

Deleting asks first, in a dialog that names what is about to go, counts what a folder takes with it, and lists the items the authorizer refuses to delete — those are kept, and the rest still goes.

## Authorization

Implement `FileExplorerAuthorizer` to control access and per-ability permissions. `$scopeKey` tells you which explorer is being accessed — the configured scope key for the standalone page, `{model-kebab}.{id}` for a record page.

```bash
php artisan filament-file-explorer:make-authorizer
```

```php
FilamentFileExplorerPlugin::make()->authorizer(\App\Support\FileExplorerAuthorizer::class)
```

The media routes (`show`, `zip-media`, `zip-folder`) never trust the scope key in their own URL: they resolve the scope's root folder through the standalone resolver, or from the roots the current session has legitimately opened, and then require the media to sit under it. A media link is therefore only served to someone whose session has a claim to that scope — a URL kept from an expired session returns 403 until the explorer page is opened again.

## Assets

There is no build step: `php artisan filament:assets` publishes the package's JS and CSS straight from its sources. Re-run it after upgrading, as you would for any Filament plugin — and keep the `@source` line from the installation steps so your theme still sees the Tailwind classes the views use.

## Testing

```bash
composer install
./vendor/bin/pest
```

> Provider order matters when booting Filament under Testbench: `Filament\Support\SupportServiceProvider` binds Livewire's `DataStore` mechanism non-shared, so it must register **before** `LivewireServiceProvider`. See `tests/TestCase.php`.

## License

MIT — see [LICENSE](LICENSE).
