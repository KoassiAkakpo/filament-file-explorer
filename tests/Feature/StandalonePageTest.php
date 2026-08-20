<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\Models\Folder;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('registers both standalone pages on the panel', function (): void {
    expect(Filament::getPanel('admin')->getPages())
        ->toContain(FileExplorer::class)
        ->toContain(FileExplorerFiles::class);
});

it('routes the standalone pages under the panel path', function (): void {
    expect(FileExplorer::getUrl(panel: 'admin'))->toEndWith('/admin/file-explorer')
        ->and(FileExplorerFiles::getUrl(panel: 'admin'))->toEndWith('/admin/file-explorer/files');
});

it('uses the configured slug', function (): void {
    config(['filament-file-explorer.standalone.slug' => 'library']);

    expect(FileExplorer::getSlug())->toBe('library');
});

it('resolves the scope key without a record', function (): void {
    Filament::setCurrentPanel('admin');

    $page = new FileExplorer;

    expect($page->fileExplorerScopeKey())->toBe('library');
});

it('mounts and exposes the root folder id', function (): void {
    Filament::setCurrentPanel('admin');

    $page = new FileExplorer;
    $page->mount();

    expect($page->rootFolderId)->toBeGreaterThan(0)
        ->and(Folder::query()->find($page->rootFolderId))->not->toBeNull();
});

it('registers a navigation entry', function (): void {
    expect(FileExplorer::shouldRegisterNavigation())->toBeTrue()
        ->and(FileExplorerFiles::shouldRegisterNavigation())->toBeFalse();
});

it('hides the page when the standalone explorer is disabled', function (): void {
    config(['filament-file-explorer.standalone.enabled' => false]);

    expect(FileExplorer::canAccess())->toBeFalse();
});

it('hides the page when the authorizer denies access', function (): void {
    app()->bind(FileExplorerAuthorizer::class, fn () => new class extends AllowAllAuthorizer
    {
        public function canAccess(string $scopeKey, int $rootFolderId): bool
        {
            return false;
        }
    });

    expect(FileExplorer::canAccess())->toBeFalse();
});

it('aborts on mount when the authorizer denies access', function (): void {
    app()->bind(FileExplorerAuthorizer::class, fn () => new class extends AllowAllAuthorizer
    {
        public function canAccess(string $scopeKey, int $rootFolderId): bool
        {
            return false;
        }
    });

    (new FileExplorer)->mount();
})->throws(HttpException::class);
