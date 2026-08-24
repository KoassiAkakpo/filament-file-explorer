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
    /**
     * What the listing can be ordered by.
     *
     * `size` sorts the files and leaves the folders on their name: a folder has
     * no size of its own, and the only honest one would be the recursive weight
     * of its subtree — a query per folder, or a denormalised column to keep in
     * step with every upload, move and delete. Folders fill the window first
     * whatever the sort, so they are never interleaved with the files they would
     * be compared against.
     */
    public const SORTS = ['name', 'date', 'type', 'size'];

    /**
     * The LIKE escape character, and the one place a search term becomes a
     * pattern.
     *
     * "!" rather than a backslash because sqlite reads '\\' as two characters
     * and rejects it, while every supported driver accepts a plain one. Public
     * because Support\Annotations matches descriptions and tag names with the
     * same term: what a search means is decided here, once.
     */
    public const LIKE_ESCAPE = '!';

    private string $search;

    private string $sortBy;

    private string $sortDir;

    private ?string $kind;

    /** @var list<string> */
    private array $kinds;

    /**
     * @param  list<string>  $kinds  the kinds a listing may show at all, beside
     *                               the single $kind the filter menu picks from
     *                               them. Empty means every kind.
     */
    public function __construct(
        private readonly int $rootFolderId,
        private readonly int $currentFolderId,
        string $search = '',
        string $sortBy = 'name',
        string $sortDir = 'asc',
        ?string $kind = null,
        private readonly ?int $tagId = null,
        array $kinds = [],
    ) {
        $this->kinds = FileKinds::normaliseMany($kinds);

        // Normalised here, so an unrecognised value narrows nothing rather than
        // emptying a folder with no explanation — and a filter for a kind the
        // restriction excludes is one of those: it would leave the folder empty
        // with a menu entry to blame that the menu never offered.
        $this->kind = FileKinds::normalise($kind);

        if ($this->kind !== null && $this->kinds !== [] && ! in_array($this->kind, $this->kinds, true)) {
            $this->kind = null;
        }

        $this->search = trim($search);
        $this->sortBy = in_array($sortBy, self::SORTS, true) ? $sortBy : 'name';

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
        $query = FolderModel::query();

        // Before the window and before the counts, like the kind filter, so the
        // totals describe what the filter left. Unlike the kind filter this one
        // narrows folders too: a folder can be tagged, and hiding folders here
        // would hide exactly what the user tagged.
        $query = app(Annotations::class)->applyTag(
            $query,
            Annotations::FOLDER,
            $this->folderTable().'.id',
            $this->tagId,
        );

        if ($this->search !== '') {
            return $this->applySearch(
                $query
                    ->whereIn('id', $this->scopeFolderIds())
                    ->where('id', '!=', $this->rootFolderId),
                Annotations::FOLDER,
                $this->folderTable().'.id',
            );
        }

        if ($this->filteringByTag()) {
            // Excluding the folder being browsed, which is in its own subtree:
            // a listing containing the folder it lists would draw it inside
            // itself, exactly as the search excludes the root.
            return $query
                ->whereIn('id', $this->subtreeFolderIds())
                ->where('id', '!=', $this->currentFolderId);
        }

        return $query->where('parent_id', $this->currentFolderId);
    }

    /**
     * @return Builder<Media>
     */
    private function filesQuery(): Builder
    {
        $query = Media::query()
            ->where('collection_name', UploadRules::collection())
            ->where('model_type', FolderModel::morphClass());

        // Before the window and before the counts, so "5 of 12" describes what
        // the filter left rather than what was there. The restriction first: a
        // picker limited to images and PDFs is not showing anything else, filter
        // or no filter.
        $query = FileKinds::applyAny($query, $this->kinds);
        $query = FileKinds::apply($query, $this->kind);

        $query = app(Annotations::class)->applyTag(
            $query,
            Annotations::FILE,
            $this->mediaTable().'.id',
            $this->tagId,
        );

        if ($this->search !== '') {
            return $this->applySearch(
                $query->whereIn('model_id', $this->scopeFolderIds()),
                Annotations::FILE,
                $this->mediaTable().'.id',
            );
        }

        if ($this->filteringByTag()) {
            return $query->whereIn('model_id', $this->subtreeFolderIds());
        }

        return $query->where('model_id', $this->currentFolderId);
    }

    /**
     * LIKE with an explicit escape character, so "%" and "_" typed in the
     * search box are literals instead of wildcards.
     *
     * The name is not the only thing a search looks at: a description and a tag
     * are worth nothing if the words in them cannot be found again, which is
     * also why neither is stored in a JSON column. All three are matched in one
     * grouped OR, so the window and the counts still describe the same set.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private function applySearch(Builder $query, string $itemType, string $idColumn): Builder
    {
        $pattern = self::likePattern($this->search);

        return $query->where(function (Builder $group) use ($pattern, $itemType, $idColumn): void {
            $group->whereRaw("name LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern]);

            app(Annotations::class)->orWhereMatches($group, $itemType, $idColumn, $pattern);
        });
    }

    /**
     * A typed term as a LIKE pattern: the wildcards a user typed are literals,
     * and the ones the search adds are its own.
     */
    public static function likePattern(string $search): string
    {
        $e = self::LIKE_ESCAPE;

        $needle = str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], trim($search));

        return '%'.$needle.'%';
    }

    private function folderTable(): string
    {
        return FolderModel::new()->getTable();
    }

    private function mediaTable(): string
    {
        return (new Media)->getTable();
    }

    /**
     * @param  Builder<Folder>  $query
     * @return Builder<Folder>
     */
    private function sortFolders(Builder $query): Builder
    {
        // A folder has neither a kind nor a size of its own, so both fall back
        // to the name. LOWER() keeps the order case-insensitive on drivers whose
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
            // Ties are common — an empty file, or several copies of one — so the
            // name breaks them before the primary key does, which keeps the
            // order readable rather than merely deterministic.
            'size' => $query->orderBy('size', $this->sortDir)->orderByRaw($label.' asc'),
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

    /**
     * Everything under the folder being browsed, itself included.
     *
     * What a tag filter answers from. Memoised per folder id by FolderTree, and
     * flushed with it on every mutation.
     *
     * @return list<int>
     */
    private function subtreeFolderIds(): array
    {
        return app(FolderTree::class)->descendantFolderIdsIncludingRoot($this->currentFolderId);
    }

    /**
     * Whether a tag is actually narrowing this listing.
     *
     * The same condition applyTag() uses, and it has to be: with annotations
     * turned off a leftover tag id narrows nothing, and a listing that widened
     * to the subtree anyway would answer a filter nobody applied with every
     * file in the tree.
     */
    private function filteringByTag(): bool
    {
        return $this->tagId !== null && Annotations::enabled();
    }
}
