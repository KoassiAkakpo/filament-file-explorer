<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver;
use Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

/**
 * Records what canAccess() was authorized against.
 */
function feAcSpyAuthorizer(bool $grant = true): object
{
    $authorizer = new class($grant) extends AllowAllAuthorizer
    {
        public array $seen = [];

        public function __construct(private bool $grant) {}

        public function canAccess(string $scopeKey, int $rootFolderId): bool
        {
            $this->seen[] = [$scopeKey, $rootFolderId];

            return $this->grant;
        }
    };

    app()->instance(FileExplorerAuthorizer::class, $authorizer);

    return $authorizer;
}

it('does not create the root folder while rendering the navigation', function (): void {
    expect(FileExplorer::canAccess())->toBeTrue()
        // Filament asks on every page of the panel, so this used to write a
        // folder each time a menu was drawn.
        ->and(Folder::query()->count())->toBe(0);

    (new FileExplorer)->mount();

    expect(Folder::query()->count())->toBe(1);
});

it('authorizes on the scope key while the scope has no root yet', function (): void {
    $authorizer = feAcSpyAuthorizer();

    FileExplorer::canAccess();

    expect($authorizer->seen)->toBe([['library', 0]]);

    // Once the root exists, the real id is what the authorizer sees.
    $root = app(FileExplorerManager::class)->ensureRoot('library', 'Library');
    app()->forgetInstance(GlobalRootResolver::class);

    FileExplorer::canAccess();

    expect($authorizer->seen[1])->toBe(['library', (int) $root->id]);
});

it('creates no root for a user it is about to deny', function (): void {
    config(['filament-file-explorer.standalone.resolver' => PerUserRootResolver::class]);
    Auth::setUser(new User(['id' => 7]));
    feAcSpyAuthorizer(grant: false);

    expect(FileExplorer::canAccess())->toBeFalse()
        ->and(Folder::query()->count())->toBe(0);
});

it('keeps working with a resolver that cannot answer without creating', function (): void {
    config(['filament-file-explorer.standalone.resolver' => FeAcCreatingResolver::class]);

    expect(FileExplorer::canAccess())->toBeTrue()
        // No ResolvesExistingRoot, so the old creating path stands rather than
        // the resolver being denied.
        ->and(Folder::query()->count())->toBe(1);
});

it('reports a misconfigured resolver instead of vanishing from the menu', function (): void {
    Exceptions::fake();

    config(['filament-file-explorer.standalone.resolver' => FeAcBrokenResolver::class]);

    expect(FileExplorer::canAccess())->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'folders table is missing');
});

it('stays quiet when there is simply no user for the scope', function (): void {
    Exceptions::fake();

    config(['filament-file-explorer.standalone.resolver' => PerUserRootResolver::class]);
    Auth::logout();

    expect(FileExplorer::canAccess())->toBeFalse();

    // A per-user resolver aborting without a user is not a misconfiguration.
    Exceptions::assertNothingReported();
});

class FeAcCreatingResolver implements FileExplorerRootResolver
{
    public function scopeKey(): string
    {
        return 'library';
    }

    public function rootFolderId(): int
    {
        return (int) app(FileExplorerManager::class)->ensureRoot('library', 'Library')->id;
    }
}

class FeAcBrokenResolver implements FileExplorerRootResolver
{
    public function scopeKey(): string
    {
        return 'library';
    }

    public function rootFolderId(): int
    {
        throw new RuntimeException('folders table is missing');
    }
}
