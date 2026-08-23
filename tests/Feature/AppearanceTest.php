<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
});

function feApCss(): string
{
    return (string) file_get_contents(__DIR__.'/../../resources/css/file-explorer.css');
}

function feApHtml(): string
{
    $rootId = app(FileExplorerRootResolver::class)->rootFolderId();

    FolderModel::query()->create([
        'name' => 'Projets',
        'slug' => 'projets-'.Str::lower(Str::random(4)),
        'parent_id' => $rootId,
    ]);

    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $rootId,
    ])->assertOk()->html();
}

it('draws one folder icon everywhere, the sidebar included', function (): void {
    $html = feApHtml();

    // The sidebar was the last place still drawing an outline heroicon, and a
    // folder that changed appearance between the tree and the folder it opens
    // was the only thing saying they were not the same object.
    expect(substr_count($html, 'folder-macos.webp'))->toBeGreaterThan(2);

    $sidebar = (string) strstr($html, 'fe-side-item');

    expect($sidebar)->not->toContain('heroicon-o-folder-open');
});

it('declares the accent once and nowhere else', function (): void {
    $css = feApCss();

    expect($css)->toContain('--fe-accent:')
        ->toContain('--fe-accent-solid:')
        ->toContain('--fe-accent-text:');

    // Teal was hardcoded in twenty-one places, which is why the explorer read as
    // a Filament panel wearing a Finder layout. One owner, or it grows back.
    foreach (['13, 148, 136', '45, 212, 191', '15, 118, 110', '#0d9488', '#14b8a6', '#0f766e'] as $teal) {
        expect($css)->not->toContain($teal);
    }
});

it('keeps the accent out of the templates, where this file cannot reach it', function (): void {
    $views = [
        __DIR__.'/../../resources/views/livewire/file-explorer.blade.php',
        __DIR__.'/../../resources/views/components/file-explorer/sidebar-tree.blade.php',
        __DIR__.'/../../resources/views/components/file-explorer/directory.blade.php',
        __DIR__.'/../../resources/views/components/file-explorer/media.blade.php',
    ];

    foreach ($views as $view) {
        // A Tailwind utility cannot read a custom property, so `text-teal-600` in
        // a template was a second definition of the accent.
        expect((string) file_get_contents($view))->not->toContain('teal-');
    }
});

it('selects with the accent rather than with grey', function (): void {
    $css = feApCss();

    // Grey is what the Finder uses for the row you came through — the trail in
    // the column view — which is a different statement from the row you have
    // selected.
    expect($css)->toContain('.fe-row--selected {
  background: rgba(var(--fe-accent), 0.14) !important;
}')
        ->toContain('.fe-column__row.is-selected {
  background: rgba(var(--fe-accent), 0.14);')
        // The trail keeps its grey, which is the distinction.
        ->toContain('.fe-column__row.is-path {
  background: rgba(24, 24, 27, 0.07);');
});

it('gives a selected icon label the solid pill the Finder gives it', function (): void {
    // The one selection cue the Finder makes unmistakable. A tinted pill with
    // tinted text is what made the icon view read as something else.
    expect(feApCss())->toContain('background: var(--fe-accent-solid);
  color: var(--fe-accent-on-solid) !important;');
});
