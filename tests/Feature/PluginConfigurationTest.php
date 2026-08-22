<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Support\Arr;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;
use Koassi\FilamentFileExplorer\Pages\StandaloneFileExplorerPage;
use Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Livewire\Features\SupportTesting\Testable;

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

it('opens in the view the panel chose', function (): void {
    config(['filament-file-explorer.view.default' => 'columns']);

    $root = app(FileExplorerManager::class)->ensureRoot('library', 'Library');

    Livewire\Livewire::test(Koassi\FilamentFileExplorer\Livewire\FileExplorer::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $root->id,
    ])->assertSet('viewMode', 'columns');
});

it('falls back to the grid when the configured view does not exist', function (): void {
    config(['filament-file-explorer.view.default' => 'carousel']);

    expect(StandaloneSettings::defaultViewMode())->toBe('grid');
});

it('still remembers what the user picked over the default', function (): void {
    config(['filament-file-explorer.view.default' => 'columns']);

    $root = app(FileExplorerManager::class)->ensureRoot('library', 'Library');

    $mount = fn (): Testable => Livewire\Livewire::test(
        Koassi\FilamentFileExplorer\Livewire\FileExplorer::class,
        ['scopeKey' => 'library', 'rootFolderId' => $root->id],
    );

    $mount()->call('setViewMode', 'details');

    $mount()->assertSet('viewMode', 'details');
});

it('takes the folder depth limit from the panel', function (): void {
    config(['filament-file-explorer.folders.max_depth' => 4]);

    expect(StandaloneSettings::maxFolderDepth())->toBe(4);

    config(['filament-file-explorer.folders.max_depth' => null]);

    expect(StandaloneSettings::maxFolderDepth())->toBeNull();

    expect(FilamentFileExplorerPlugin::make()->maxFolderDepth(2)->getMaxFolderDepth())->toBe(2);
});

it('lets a panel rewrite the columns of the files table', function (): void {
    $columns = StandaloneSettings::tableColumns([
        'preview' => 'a',
        'file_name' => 'b',
        'added_by' => 'c',
    ]);

    // Untouched, they come out in order and without their keys, which is what
    // Filament expects.
    expect($columns)->toBe(['a', 'b', 'c']);

    $plugin = FilamentFileExplorerPlugin::make()
        ->tableColumns(fn (array $columns): array => Arr::except($columns, ['preview']));

    expect($plugin->getTableColumnsCallback())->toBeInstanceOf(Closure::class)
        ->and(array_values(($plugin->getTableColumnsCallback())(['preview' => 'a', 'file_name' => 'b'])))
        ->toBe(['b']);
});
