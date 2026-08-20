<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages\Concerns;

trait InteractsWithFileExplorer
{
    public int $rootFolderId = 0;

    abstract public function fileExplorerScopeKey(): string;

    abstract protected function resolveFileExplorerRootFolderId(): int;

    public function mountFileExplorer(): void
    {
        $this->rootFolderId = $this->resolveFileExplorerRootFolderId();

        abort_unless(
            app(\Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer::class)
                ->canAccess($this->fileExplorerScopeKey(), $this->rootFolderId),
            403
        );
    }
}
