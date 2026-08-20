<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer as FileExplorerPage;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('renders the standalone explorer page', function (): void {
    // Guards against the page view calling a non-public method on the
    // component: Livewire's __call would raise BadMethodCallException.
    Livewire::test(FileExplorerPage::class)->assertOk();
});

it('renders the standalone files page', function (): void {
    Livewire::test(FileExplorerFiles::class)->assertOk();
});

it('boots the explorer component with the resolved scope and root', function (): void {
    $page = new FileExplorerPage;
    $page->mount();

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ])
        ->assertOk()
        ->assertSet('scopeKey', 'library')
        ->assertSet('rootFolderId', $page->rootFolderId);
});
