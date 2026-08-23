<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Route;
use Koassi\FilamentFileExplorer\Commands\InstallCommand;
use Koassi\FilamentFileExplorer\Commands\MakeAuthorizerCommand;
use Koassi\FilamentFileExplorer\Commands\MakeFolderMigrationCommand;
use Koassi\FilamentFileExplorer\Commands\MakePageCommand;
use Koassi\FilamentFileExplorer\Commands\PurgeTrashCommand;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Http\Controllers\MediaController;
use Koassi\FilamentFileExplorer\Http\Controllers\ShareController;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer;
use Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver;
use Koassi\FilamentFileExplorer\Resolvers\PerTenantRootResolver;
use Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\Annotations;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\MediaScope;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Sharing;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\Uploader;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FilamentFileExplorerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-file-explorer')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasAssets()
            ->hasCommands([
                InstallCommand::class,
                MakePageCommand::class,
                MakeAuthorizerCommand::class,
                MakeFolderMigrationCommand::class,
                PurgeTrashCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(FileExplorerManager::class);
        $this->app->alias(FileExplorerManager::class, 'filament-file-explorer');

        // Scoped, not shared: FolderTree memoises tree walks for the duration
        // of one request, and a long-lived worker would otherwise keep serving
        // a tree that has since been reorganised.
        $this->app->scoped(FolderTree::class);

        // Scoped for the same reason: it memoises how many bytes a scope
        // already takes, which the very next request may have changed.
        $this->app->scoped(Quota::class);

        // Scoped as well: it memoises uploader names, which a profile edit in
        // the next request would make wrong.
        $this->app->scoped(Uploader::class);

        // Scoped too: it memoises what a scope may do, and that answer belongs
        // to whoever was authenticated for this request and no one else.
        $this->app->scoped(Abilities::class);

        // Scoped as well: it memoises the tags of the rows on screen, which the
        // next request will be drawing from a different folder.
        $this->app->scoped(Annotations::class);

        // Stateless, so a singleton is enough: it reads and writes rows and
        // memoises nothing.
        $this->app->singleton(MediaScope::class);
        $this->app->singleton(Sharing::class);

        $this->app->singleton(FileExplorerAuthorizer::class, function ($app) {
            $class = config('filament-file-explorer.authorizer');

            return $app->make($class);
        });

        // Not a singleton on the interface: the resolver class is panel-scoped,
        // so it is looked up per resolution. The concrete resolvers below are
        // singletons, which is what memoises the root folder lookup.
        $this->app->bind(
            FileExplorerRootResolver::class,
            fn ($app) => $app->make(StandaloneSettings::resolverClass()),
        );

        $this->app->singleton(GlobalRootResolver::class);
        $this->app->singleton(PerUserRootResolver::class);
        $this->app->singleton(PerTenantRootResolver::class);
    }

    public function packageBooted(): void
    {
        $this->registerFilamentAssets();

        Livewire::addNamespace(
            'filament-file-explorer',
            viewPath: __DIR__.'/../resources/views/livewire',
            classNamespace: 'Koassi\\FilamentFileExplorer\\Livewire',
        );

        // Livewire 4 blade tags use dots; :: form is for @livewire() / namespaces.
        Livewire::component('filament-file-explorer::file-explorer', FileExplorer::class);
        Livewire::component('filament-file-explorer.file-explorer', FileExplorer::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/filament-file-explorer'),
            ], 'filament-file-explorer-stubs');

            $this->publishes([
                __DIR__.'/../resources/images' => public_path('vendor/filament-file-explorer'),
            ], 'filament-file-explorer-assets');
        }

        $this->registerRoutes();
        $this->forgetAnnotationsOfDeletedItems();
    }

    /**
     * Descriptions and tags follow their item into the grave.
     *
     * Registered here rather than at each delete site because there are several
     * — the explorer's delete, the trash purge, the files table, the purge
     * command — and one that forgot would leave a tag on the filter menu
     * pointing at nothing.
     *
     * Both listeners check the row is *ours* before deleting anything, and that
     * is not an optimisation: an annotation is keyed by (kind, id), so a media
     * row of some other model whose id happens to match one of our files would
     * otherwise take that file's description with it.
     */
    protected function forgetAnnotationsOfDeletedItems(): void
    {
        Media::deleted(function (Media $media): void {
            $ours = $media->model_type === FolderModel::morphClass()
                && in_array($media->collection_name, [UploadRules::collection(), Trash::collection()], true);

            if ($ours) {
                app(Annotations::class)->forgetItem(Annotations::FILE, (int) $media->id);
            }
        });

        // forceDeleted, not deleted: a folder the trash took is soft-deleted and
        // coming back, and it must come back with its tags. Registered on the
        // configured model because Eloquent fires model events under the class
        // of the instance, so a listener on the package's Folder would never
        // hear from a host app's subclass.
        $folderModel = FolderModel::class();

        $folderModel::forceDeleted(function ($folder): void {
            app(Annotations::class)->forgetItem(Annotations::FOLDER, (int) $folder->id);
        });
    }

    /**
     * Registered straight from the sources.
     *
     * There is no build step, so a resources/dist copy only meant the shipped
     * asset and the edited one could drift — and they did, silently, since
     * nothing fails when the copy is forgotten. filament:assets publishes these
     * to the host's public directory either way.
     */
    protected function registerFilamentAssets(): void
    {
        FilamentAsset::register([
            Css::make('filament-file-explorer', __DIR__.'/../resources/css/file-explorer.css'),
            Js::make('filament-file-explorer', __DIR__.'/../resources/js/file-explorer.js'),
        ], 'koassi/filament-file-explorer');
    }

    protected function registerRoutes(): void
    {
        $config = config('filament-file-explorer.routes');

        Route::middleware($config['middleware'] ?? ['web', 'auth'])
            ->prefix($config['prefix'] ?? 'file-explorer/media')
            ->name($config['name'] ?? 'filament-file-explorer.media.')
            ->group(function (): void {
                Route::get('{scopeKey}/files/{media}', [MediaController::class, 'show'])
                    ->name('show');
                Route::get('{scopeKey}/files/{media}/zip', [MediaController::class, 'zipMedia'])
                    ->name('zip-media');
                Route::get('{scopeKey}/folders/{folder}/zip', [MediaController::class, 'zipFolder'])
                    ->name('zip-folder');
                Route::get('{scopeKey}/selection/zip', [MediaController::class, 'zipSelection'])
                    ->name('zip-selection');
            });

        if (! Sharing::enabled()) {
            return;
        }

        $share = config('filament-file-explorer.share.routes');

        // Its own group, because its middleware is the point: no `auth`. The
        // token is the only credential and the only parameter — there is no
        // scope key to tamper with here.
        Route::middleware($share['middleware'] ?? ['web'])
            ->prefix($share['prefix'] ?? 'file-explorer/share')
            ->name($share['name'] ?? 'filament-file-explorer.share.')
            ->group(function (): void {
                Route::get('{token}', [ShareController::class, 'show'])->name('show');
            });
    }
}
