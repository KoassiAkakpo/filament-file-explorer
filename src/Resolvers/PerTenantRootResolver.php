<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Resolvers;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\ResolvesExistingRoot;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\HasFileExplorerModel;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * One root folder per Filament tenant.
 *
 * When the tenant model uses HasFileExplorer its own root folder is reused, so
 * the standalone page and any record-scoped page share the same tree.
 */
class PerTenantRootResolver implements ResolvesExistingRoot
{
    protected ?int $rootFolderId = null;

    public function scopeKey(): string
    {
        return StandaloneSettings::scopeKey().'.'.$this->tenant()->getKey();
    }

    public function rootFolderId(): int
    {
        if ($this->rootFolderId !== null) {
            return $this->rootFolderId;
        }

        $tenant = $this->tenant();

        if (HasFileExplorerModel::uses($tenant)) {
            return $this->rootFolderId = (int) HasFileExplorerModel::assert($tenant)
                ->ensureFileExplorerRoot()
                ->id;
        }

        return $this->rootFolderId = (int) app(FileExplorerManager::class)->ensureRoot(
            $this->rootSlug($tenant),
            StandaloneSettings::rootName(),
        )->id;
    }

    public function existingRootFolderId(): ?int
    {
        if ($this->rootFolderId !== null) {
            return $this->rootFolderId;
        }

        $tenant = $this->tenant();

        if (HasFileExplorerModel::uses($tenant)) {
            // The tenant's own root, if it already has one: reading the foreign
            // key never creates a folder the way ensureFileExplorerRoot() does.
            $key = HasFileExplorerModel::assert($tenant)->fileExplorerFolderForeignKey();
            $id = $tenant->getAttribute($key);

            return filled($id) ? $this->rootFolderId = (int) $id : null;
        }

        $id = app(FileExplorerManager::class)->findRoot($this->rootSlug($tenant))?->id;

        if ($id === null) {
            return null;
        }

        return $this->rootFolderId = (int) $id;
    }

    protected function rootSlug(Model $tenant): string
    {
        return StandaloneSettings::rootSlug().'-'.Str::slug((string) $tenant->getKey());
    }

    protected function tenant(): Model
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Model, 403);

        return $tenant;
    }
}
