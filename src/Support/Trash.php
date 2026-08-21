<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Moving things aside instead of destroying them.
 *
 * Folders rely on soft deletes, so a trashed folder drops out of every
 * `FolderModel::query()` the explorer runs. Media Library has no soft deletes, so a
 * trashed file moves to a separate collection instead: every listing and every
 * containment check already filters on the explorer collection, which makes
 * trashed files disappear from them for free — and keeps the file on disk,
 * untouched, so restoring is a database write rather than a copy.
 */
final class Trash
{
    public static function enabled(): bool
    {
        return (bool) config('filament-file-explorer.trash.enabled', true);
    }

    public static function collection(): string
    {
        $collection = (string) config('filament-file-explorer.trash.collection', 'file-explorer-trash');

        // Sharing the live collection would make the trash invisible to itself.
        return $collection !== UploadRules::collection() ? $collection : UploadRules::collection().'-trash';
    }

    public function trashMedia(Media $media, bool $withFolder = false): void
    {
        $media->collection_name = self::collection();
        $media->setCustomProperty('trashed_at', Carbon::now()->toIso8601String());
        $media->setCustomProperty('trashed_with_folder', $withFolder);
        $media->save();
    }

    /**
     * Trashes a folder and everything under it. The whole subtree is marked so
     * the explorer keeps showing one entry — the folder — rather than every
     * file it contained.
     */
    public function trashFolder(Folder $folder): void
    {
        $ids = $this->subtreeIds((int) $folder->id);

        foreach ($this->mediaIn($ids, UploadRules::collection())->get() as $media) {
            $this->trashMedia($media, withFolder: true);
        }

        FolderModel::query()->whereIn('id', $ids)->delete();
    }

    public function restoreMedia(Media $media): void
    {
        $folder = FolderModel::query()->withTrashed()->find($media->model_id);

        // A new upload may have taken the name while this one sat in the trash,
        // so restoring must not reintroduce the duplicate. Media Library renames
        // the file on disk when file_name changes.
        if ($folder !== null) {
            $index = FileNames::availableIndex($folder, (string) $media->file_name);
            $media->file_name = FileNames::applyIndex((string) $media->file_name, $index);
            $media->name = FileNames::applyIndexToLabel((string) $media->name, $index);
        }

        $media->collection_name = UploadRules::collection();
        $media->forgetCustomProperty('trashed_at');
        $media->forgetCustomProperty('trashed_with_folder');
        $media->save();
    }

    /**
     * Restores a folder, its subtree, and the files that went down with it —
     * but not files that had been trashed on their own beforehand: those stay
     * in the trash, where the user put them.
     */
    public function restoreFolder(Folder $folder): void
    {
        $ids = $this->subtreeIds((int) $folder->id);

        FolderModel::query()->withTrashed()->whereIn('id', $ids)->restore();

        foreach ($this->mediaIn($ids, self::collection())->get() as $media) {
            if ($media->getCustomProperty('trashed_with_folder') === true) {
                $this->restoreMedia($media);
            }
        }
    }

    public function purgeMedia(Media $media): void
    {
        $media->delete();
    }

    public function purgeFolder(Folder $folder): void
    {
        $ids = $this->subtreeIds((int) $folder->id);

        // Media Library removes the files from the disk on delete.
        foreach (Media::query()
            ->where('model_type', FolderModel::morphClass())
            ->whereIn('model_id', $ids)
            ->get() as $media) {
            $media->delete();
        }

        FolderModel::query()->withTrashed()->whereIn('id', $ids)->forceDelete();
    }

    /**
     * What the trash view shows for one scope: trashed folders whose parent is
     * not itself trashed, and trashed files still sitting in a live folder.
     *
     * @return array{folders: Collection<int, Folder>, files: Collection<int, Media>}
     */
    public function items(int $rootFolderId): array
    {
        $ids = $this->subtreeIds($rootFolderId);

        $trashedIds = FolderModel::query()->onlyTrashed()->whereIn('id', $ids)->pluck('id')->all();

        $folders = FolderModel::query()->onlyTrashed()
            ->whereIn('id', $trashedIds)
            // A folder inside another trashed folder is reachable through it.
            // NOT IN would also drop a null parent, hence the explicit branch.
            ->where(fn ($query) => $query
                ->whereNull('parent_id')
                ->orWhereNotIn('parent_id', $trashedIds === [] ? [0] : $trashedIds))
            ->orderByDesc('deleted_at')
            ->get();

        $liveIds = array_values(array_diff($ids, $trashedIds));

        $files = $this->mediaIn($liveIds, self::collection())
            ->orderByDesc('id')
            ->get();

        return ['folders' => $folders, 'files' => $files];
    }

    public function count(int $rootFolderId): int
    {
        $items = $this->items($rootFolderId);

        return $items['folders']->count() + $items['files']->count();
    }

    /**
     * Purges everything trashed longer ago than $days, across every scope.
     *
     * @return int items purged
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = Carbon::now()->subDays(max(0, $days));
        $purged = 0;

        foreach (FolderModel::query()->onlyTrashed()->where('deleted_at', '<', $cutoff)->get() as $folder) {
            // Skip the ones reachable through an older trashed ancestor: the
            // ancestor's own purge takes them.
            if ($folder->parent_id !== null && FolderModel::query()->onlyTrashed()->whereKey($folder->parent_id)->exists()) {
                continue;
            }

            $this->purgeFolder($folder);
            $purged++;
        }

        foreach (Media::query()->where('collection_name', self::collection())->get() as $media) {
            $trashedAt = $media->getCustomProperty('trashed_at');

            if (! is_string($trashedAt) || Carbon::parse($trashedAt)->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            $this->purgeMedia($media);
            $purged++;
        }

        return $purged;
    }

    /**
     * Folder ids of a subtree, trashed ones included: the trash has to see what
     * the explorer no longer does.
     *
     * @return list<int>
     */
    public function subtreeIds(int $rootFolderId): array
    {
        $ids = [$rootFolderId];
        $frontier = [$rootFolderId];

        while ($frontier !== []) {
            $children = FolderModel::query()->withTrashed()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $ids));
            $ids = array_values(array_unique([...$ids, ...$children]));
        }

        return $ids;
    }

    /**
     * @param  list<int>  $folderIds
     * @return Builder<Media>
     */
    private function mediaIn(array $folderIds, string $collection): Builder
    {
        return Media::query()
            ->where('collection_name', $collection)
            ->where('model_type', FolderModel::morphClass())
            ->whereIn('model_id', $folderIds === [] ? [0] : $folderIds);
    }
}
