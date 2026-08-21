<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Models\Folder;

class FileExplorerManager
{
    public function collection(): string
    {
        return UploadRules::collection();
    }

    public function createRoot(string $name, ?string $slug = null): Folder
    {
        $slug ??= Str::slug($name) ?: ('folder-'.Str::lower(Str::random(8)));

        return FolderModel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => null,
        ]);
    }

    /**
     * Idempotent root folder lookup, used by the standalone page resolvers.
     *
     * The folders table has a unique index on ['parent_id', 'slug'], but MySQL
     * does not dedupe rows whose parent_id is NULL — so concurrent first hits
     * could each create a root. A short lock closes that window; if the cache
     * store cannot lock we fall through to the unlocked path rather than fail.
     */
    public function ensureRoot(string $slug, string $name): Folder
    {
        if ($folder = $this->findRoot($slug)) {
            return $folder;
        }

        try {
            $lock = Cache::lock('filament-file-explorer.root.'.$slug, 10);

            return $lock->block(5, fn (): Folder => $this->findRoot($slug) ?? $this->createRoot($name, $slug));
        } catch (\Throwable) {
            return $this->findRoot($slug) ?? $this->createRoot($name, $slug);
        }
    }

    public function findRoot(string $slug): ?Folder
    {
        return FolderModel::query()
            ->whereNull('parent_id')
            ->where('slug', $slug)
            ->first();
    }

    public function createChild(Folder $parent, string $name, ?string $slug = null): Folder
    {
        $slug ??= Str::slug($name) ?: ('folder-'.Str::lower(Str::random(8)));

        return FolderModel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parent->id,
        ]);
    }
}
