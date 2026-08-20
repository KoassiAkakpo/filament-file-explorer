<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Resolvers;

use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * One shared root folder for the whole application.
 *
 * Bound as a singleton so the root is resolved (and created) at most once per
 * request, even though `canAccess()` runs on every navigation render.
 */
class GlobalRootResolver implements FileExplorerRootResolver
{
    protected ?int $rootFolderId = null;

    public function scopeKey(): string
    {
        return StandaloneSettings::scopeKey();
    }

    public function rootFolderId(): int
    {
        return $this->rootFolderId ??= (int) app(FileExplorerManager::class)->ensureRoot(
            StandaloneSettings::rootSlug(),
            StandaloneSettings::rootName(),
        )->id;
    }
}
