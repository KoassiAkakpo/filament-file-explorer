<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The explorer listing, resolved in SQL.
 *
 * Sorting and windowing belong in the database: a folder in a standalone
 * library legitimately holds thousands of files, and loading the whole
 * collection to sort it in PHP is what made such a folder unusable.
 */
final class FolderListing
{
    private string $search;

    private string $sortBy;

    private string $sortDir;

    public function __construct(
        private readonly int $rootFolderId,
        private readonly int $currentFolderId,
        string $search = '',
        string $sortBy = 'name',
        string $sortDir = 'asc',
    ) {
        $this->search = trim($search);
        $this->sortBy = in_array($sortBy, ['name', 'date', 'type'], true) ? $sortBy : 'name';

        // Normalised here because both end up inside an ORDER BY expression.
        $this->sortDir = $sortDir === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Folders first, then files, together windowed to $limit items.
     *
     * The file budget comes from the *total* number of folders rather than the
     * number fetched, so raising $limit only ever appends: the window stays
     * stable across successive "load more" calls.
     *
     * @return array{folders: Collection<int, Folder>, files: Collection<int, Media>, shown: int, total: int, hasMore: bool}
     */
    public function window(int $limit): array
    {
        $limit = max(1, $limit);

        $folderTotal = $this->foldersQuery()->count();
        $fileTotal = $this->filesQuery()->count();

        $folders = $this->sortFolders($this->foldersQuery())
            ->withCount([
                'children',
                'media as file_explorer_count' => fn ($query) => $query->where('collection_name', UploadRules::collection()),
            ])
            ->limit($limit)
            ->get();

        $fileLimit = max(0, $limit - $folderTotal);

        $files = $fileLimit > 0
            ? $this->sortFiles($this->filesQuery())->limit($fileLimit)->get()
            : new Collection;

        $shown = $folders->count() + $files->count();
        $total = $folderTotal + $fileTotal;

        return [
            'folders' => $folders,
            'files' => $files,
            'shown' => $shown,
            'total' => $total,
            'hasMore' => $shown < $total,
        ];
    }

    /**
     * @return Builder<Folder>
     */
    private function foldersQuery(): Builder
    {
        if ($this->search === '') {
            return Folder::query()->where('parent_id', $this->currentFolderId);
        }

        return $this->applySearch(
            Folder::query()
                ->whereIn('id', $this->scopeFolderIds())
                ->where('id', '!=', $this->rootFolderId)
        );
    }

    /**
     * @return Builder<Media>
     */
    private function filesQuery(): Builder
    {
        $query = Media::query()
            ->where('collection_name', UploadRules::collection())
            ->where('model_type', (new Folder)->getMorphClass());

        if ($this->search === '') {
            return $query->where('model_id', $this->currentFolderId);
        }

        return $this->applySearch($query->whereIn('model_id', $this->scopeFolderIds()));
    }

    /**
     * LIKE with an explicit escape character, so "%" and "_" typed in the
     * search box are literals instead of wildcards. The escape is "!" rather
     * than a backslash because sqlite reads '\\' as two characters and rejects
     * it, while every supported driver accepts a plain one.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applySearch(Builder $query): Builder
    {
        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->search);

        return $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$needle.'%']);
    }

    /**
     * @param  Builder<Folder>  $query
     * @return Builder<Folder>
     */
    private function sortFolders(Builder $query): Builder
    {
        // A folder has no kind of its own, so sorting by type falls back to the
        // name. LOWER() keeps the order case-insensitive on drivers whose
        // default collation is not (sqlite, Postgres).
        $query = $this->sortBy === 'date'
            ? $query->orderBy('updated_at', $this->sortDir)
            : $query->orderByRaw('LOWER(name) '.$this->sortDir);

        return $query->orderBy('id');
    }

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    private function sortFiles(Builder $query): Builder
    {
        $label = "LOWER(COALESCE(NULLIF(name, ''), file_name))";

        $query = match ($this->sortBy) {
            'date' => $query->orderBy('created_at', $this->sortDir),
            // Sorting by extension cannot be expressed the same way on every
            // driver, and the mime type groups files into the same kinds.
            'type' => $query->orderBy('mime_type', $this->sortDir)->orderByRaw($label.' asc'),
            default => $query->orderByRaw($label.' '.$this->sortDir),
        };

        // Every window needs a deterministic tiebreak, or "load more" could
        // repeat or skip rows that share a sort value.
        return $query->orderBy('id');
    }

    /**
     * @return list<int>
     */
    private function scopeFolderIds(): array
    {
        return app(FolderTree::class)->descendantFolderIdsIncludingRoot($this->rootFolderId);
    }
}
