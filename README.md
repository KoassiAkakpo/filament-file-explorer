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

## Authorization

Implement `FileExplorerAuthorizer` to control access and per-ability permissions. `$scopeKey` tells you which explorer is being accessed — the configured scope key for the standalone page, `{model-kebab}.{id}` for a record page.

```bash
php artisan filament-file-explorer:make-authorizer
```

```php
FilamentFileExplorerPlugin::make()->authorizer(\App\Support\FileExplorerAuthorizer::class)
```

The media routes (`show`, `zip-media`, `zip-folder`) never trust the scope key in their own URL: they resolve the scope's root folder through the standalone resolver, or from the roots the current session has legitimately opened, and then require the media to sit under it. A media link is therefore only served to someone whose session has a claim to that scope — a URL kept from an expired session returns 403 until the explorer page is opened again.

## Testing

```bash
composer install
./vendor/bin/pest
```

> Provider order matters when booting Filament under Testbench: `Filament\Support\SupportServiceProvider` binds Livewire's `DataStore` mechanism non-shared, so it must register **before** `LivewireServiceProvider`. See `tests/TestCase.php`.

## License

MIT — see [LICENSE](LICENSE).
