<?php

declare(strict_types=1);

use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

it('binds the configured root resolver', function (): void {
    expect(app(FileExplorerRootResolver::class))->toBeInstanceOf(GlobalRootResolver::class);
});

it('creates the global root folder on first resolution', function (): void {
    expect(Folder::query()->count())->toBe(0);

    $rootId = app(FileExplorerRootResolver::class)->rootFolderId();

    $root = Folder::query()->findOrFail($rootId);

    expect($root->parent_id)->toBeNull()
        ->and($root->slug)->toBe('library')
        ->and($root->name)->toBe('Library');
});

it('reuses the same root folder across resolutions', function (): void {
    $first = app(FileExplorerRootResolver::class)->rootFolderId();

    // Fresh instance: proves idempotence comes from the query, not the memo.
    app()->forgetInstance(GlobalRootResolver::class);
    $second = app(FileExplorerRootResolver::class)->rootFolderId();

    expect($second)->toBe($first)
        ->and(Folder::query()->whereNull('parent_id')->count())->toBe(1);
});

it('does not treat a same-slug child folder as the root', function (): void {
    $root = app(FileExplorerManager::class)->ensureRoot('library', 'Library');

    // A nested folder may legitimately reuse the root slug.
    app(FileExplorerManager::class)->createChild($root, 'Library', 'library');

    expect(app(FileExplorerManager::class)->ensureRoot('library', 'Library')->id)->toBe($root->id);
});

it('exposes the configured scope key', function (): void {
    expect(app(FileExplorerRootResolver::class)->scopeKey())->toBe('library')
        ->and(StandaloneSettings::scopeKey())->toBe('library');
});

it('honours config overrides for the root folder', function (): void {
    config([
        'filament-file-explorer.standalone.scope_key' => 'documents',
        'filament-file-explorer.standalone.root.slug' => 'documents',
        'filament-file-explorer.standalone.root.name' => 'Documents',
    ]);

    $resolver = new GlobalRootResolver;

    expect($resolver->scopeKey())->toBe('documents')
        ->and(Folder::query()->findOrFail($resolver->rootFolderId())->slug)->toBe('documents');
});
