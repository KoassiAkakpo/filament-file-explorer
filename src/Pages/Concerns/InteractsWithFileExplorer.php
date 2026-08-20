<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages\Concerns;

use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;

trait InteractsWithFileExplorer
{
    public int $rootFolderId = 0;

    abstract public function fileExplorerScopeKey(): string;

    abstract protected function resolveFileExplorerRootFolderId(): int;

    public function mountFileExplorer(): void
    {
        $this->rootFolderId = $this->resolveFileExplorerRootFolderId();

        $scopeKey = $this->fileExplorerScopeKey();

        abort_unless(
            app(FileExplorerAuthorizer::class)
                ->canAccess($scopeKey, $this->rootFolderId),
            403
        );

        // Media and zip URLs rendered by this page (and by its table) carry the
        // scope key only, so the routes serving them need a trustworthy record
        // of the root it maps to. See Support\ScopeRoots.
        ScopeRoots::remember($scopeKey, $this->rootFolderId);
    }
}
