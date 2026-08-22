<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\MimeIcon;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function fePrefComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => app(FileExplorerRootResolver::class)->rootFolderId(),
    ]);
}

it('remembers the view mode and the sort across mounts', function (): void {
    fePrefComponent()
        ->call('setViewMode', 'details')
        ->call('setSort', 'date', 'desc');

    // A fresh mount is what a page reload or a navigation looks like.
    fePrefComponent()
        ->assertSet('viewMode', 'details')
        ->assertSet('sortBy', 'date')
        ->assertSet('sortDir', 'desc');
});

it('keeps the preferences of two scopes apart', function (): void {
    $root = app(FileExplorerRootResolver::class)->rootFolderId();

    fePrefComponent()->call('setViewMode', 'details');

    Livewire::test(FileExplorerComponent::class, ['scopeKey' => 'other', 'rootFolderId' => $root])
        ->assertSet('viewMode', 'grid');

    fePrefComponent()->assertSet('viewMode', 'details');
});

it('falls back to the default when a stored preference no longer exists', function (): void {
    session(['view.library' => ['viewMode' => 'coverflow', 'sortBy' => 'colour', 'sortDir' => 'sideways']]);

    fePrefComponent()
        ->assertSet('viewMode', 'grid')
        ->assertSet('sortBy', 'name')
        ->assertSet('sortDir', 'asc');
});

it('accepts by default every kind the shipped icons can render', function (): void {
    // The explorer used to draw an xlsx or mp4 icon for files it refused to
    // accept in the first place.
    $icons = collect(File::files(__DIR__.'/../../resources/views/components/file-explorer/icons/mimes'))
        ->map(fn ($file): string => str_replace('.blade.php', '', $file->getFilename()))
        ->reject(fn (string $icon): bool => $icon === 'file')
        ->values();

    $reachable = collect(UploadRules::acceptedExtensions())
        ->map(fn (string $extension): string => MimeIcon::resolve($extension))
        ->unique();

    expect($icons)->not->toBeEmpty()
        ->and($icons->diff($reachable)->all())->toBe([]);
});

it('does not accept svg, which the media route would serve inline', function (): void {
    expect(UploadRules::acceptedExtensions())->not->toContain('svg')
        ->and(UploadRules::acceptedMimeTypes())->not->toContain('image/svg+xml');
});

it('falls back for a mode the package used to have', function (): void {
    // `list` and `table` were dropped for being variations on `details`. Anyone
    // who had picked one keeps a stored preference naming it, and must land on
    // the default rather than on a view that no longer renders.
    session(['view.library' => ['viewMode' => 'list', 'sortBy' => 'name', 'sortDir' => 'asc']]);

    fePrefComponent()->assertSet('viewMode', 'grid');

    session(['view.library' => ['viewMode' => 'table', 'sortBy' => 'name', 'sortDir' => 'asc']]);

    fePrefComponent()->assertSet('viewMode', 'grid');
});

it('offers three views and no more', function (): void {
    expect(StandaloneSettings::VIEW_MODES)->toBe(['grid', 'columns', 'details']);
});
