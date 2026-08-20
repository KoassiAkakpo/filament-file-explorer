<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Resolvers;

use Koassi\FilamentFileExplorer\Contracts\ResolvesExistingRoot;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * One shared root folder for the whole application.
 *
 * Bound as a singleton so the root is resolved (and created) at most once per
 * request, even though `canAccess()` runs on every navigation render.
 */
class GlobalRootResolver implements ResolvesExistingRoot
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

    public function existingRootFolderId(): ?int
    {
        if ($this->rootFolderId !== null) {
            return $this->rootFolderId;
        }

        $id = app(FileExplorerManager::class)->findRoot(StandaloneSettings::rootSlug())?->id;

        if ($id === null) {
            return null;
        }

        return $this->rootFolderId = (int) $id;
    }
}
