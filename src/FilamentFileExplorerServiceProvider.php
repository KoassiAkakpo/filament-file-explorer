<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer;

use Koassi\FilamentFileExplorer\Commands\InstallCommand;
use Koassi\FilamentFileExplorer\Commands\MakeAuthorizerCommand;
use Koassi\FilamentFileExplorer\Commands\MakeFolderMigrationCommand;
use Koassi\FilamentFileExplorer\Commands\MakePageCommand;
use Koassi\FilamentFileExplorer\Commands\PurgeTrashCommand;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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

        $this->app->singleton(\Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver::class);
        $this->app->singleton(\Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver::class);
        $this->app->singleton(\Koassi\FilamentFileExplorer\Resolvers\PerTenantRootResolver::class);
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
    }

    protected function registerFilamentAssets(): void
    {
        FilamentAsset::register([
            Css::make('filament-file-explorer', __DIR__.'/../resources/dist/file-explorer.css'),
            Js::make('filament-file-explorer', __DIR__.'/../resources/dist/file-explorer.js'),
        ], 'Koassi/filament-file-explorer');
    }

    protected function registerRoutes(): void
    {
        $config = config('filament-file-explorer.routes');

        Route::middleware($config['middleware'] ?? ['web', 'auth'])
            ->prefix($config['prefix'] ?? 'file-explorer/media')
            ->name($config['name'] ?? 'filament-file-explorer.media.')
            ->group(function (): void {
                Route::get('{scopeKey}/files/{media}', [\Koassi\FilamentFileExplorer\Http\Controllers\MediaController::class, 'show'])
                    ->name('show');
                Route::get('{scopeKey}/files/{media}/zip', [\Koassi\FilamentFileExplorer\Http\Controllers\MediaController::class, 'zipMedia'])
                    ->name('zip-media');
                Route::get('{scopeKey}/folders/{folder}/zip', [\Koassi\FilamentFileExplorer\Http\Controllers\MediaController::class, 'zipFolder'])
                    ->name('zip-folder');
                Route::get('{scopeKey}/selection/zip', [\Koassi\FilamentFileExplorer\Http\Controllers\MediaController::class, 'zipSelection'])
                    ->name('zip-selection');
            });
    }
}
