<?php

declare(strict_types=1);

use Filament\Panel;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;
use Koassi\FilamentFileExplorer\Pages\StandaloneFileExplorerPage;
use Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver;

it('registers the packaged pages by default', function (): void {
    $panel = Panel::make()->id('test-default');

    FilamentFileExplorerPlugin::make()->register($panel);

    expect($panel->getPages())
        ->toContain(FileExplorer::class)
        ->toContain(FileExplorerFiles::class);
});

it('can register a replacement explorer page', function (): void {
    $panel = Panel::make()->id('test-custom');

    FilamentFileExplorerPlugin::make()
        ->explorerPage(CustomExplorerPage::class)
        ->withoutFilesPage()
        ->register($panel);

    expect($panel->getPages())
        ->toContain(CustomExplorerPage::class)
        ->not->toContain(FileExplorer::class)
        ->not->toContain(FileExplorerFiles::class);
});

it('can skip page registration entirely', function (): void {
    $panel = Panel::make()->id('test-none');

    FilamentFileExplorerPlugin::make()->withoutPages()->register($panel);

    expect($panel->getPages())->toBe([]);
});

it('skips page registration when standalone mode is disabled in config', function (): void {
    config(['filament-file-explorer.standalone.enabled' => false]);

    $panel = Panel::make()->id('test-disabled');

    FilamentFileExplorerPlugin::make()->register($panel);

    expect($panel->getPages())->toBe([]);
});

it('exposes fluent settings to the pages', function (): void {
    $plugin = FilamentFileExplorerPlugin::make()
        ->slug('library')
        ->scopeKey('shared-library')
        ->rootFolder('Shared library', 'shared-library')
        ->rootResolver(PerUserRootResolver::class)
        ->navigationGroup('Content')
        ->navigationSort(3)
        ->navigationLabel('Files');

    expect($plugin->getStandaloneSlug())->toBe('library')
        ->and($plugin->getStandaloneFilesSlug())->toBe('library/files')
        ->and($plugin->getScopeKey())->toBe('shared-library')
        ->and($plugin->getRootFolderSlug())->toBe('shared-library')
        ->and($plugin->getRootFolderName())->toBe('Shared library')
        ->and($plugin->getRootResolverClass())->toBe(PerUserRootResolver::class)
        ->and($plugin->getNavigationGroup())->toBe('Content')
        ->and($plugin->getNavigationSort())->toBe(3)
        ->and($plugin->getNavigationLabel())->toBe('Files');
});

class CustomExplorerPage extends StandaloneFileExplorerPage
{
    //
}
