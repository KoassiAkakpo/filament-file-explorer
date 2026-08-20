<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Resolvers;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\HasFileExplorerModel;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * One root folder per Filament tenant.
 *
 * When the tenant model uses HasFileExplorer its own root folder is reused, so
 * the standalone page and any record-scoped page share the same tree.
 */
class PerTenantRootResolver implements FileExplorerRootResolver
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
            StandaloneSettings::rootSlug().'-'.Str::slug((string) $tenant->getKey()),
            StandaloneSettings::rootName(),
        )->id;
    }

    protected function tenant(): Model
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Model, 403);

        return $tenant;
    }
}
