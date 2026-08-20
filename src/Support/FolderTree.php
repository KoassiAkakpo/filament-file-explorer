<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * Tree walks over the folder table, memoised for the current request.
 *
 * Containment is checked on every action and every media URL, and the
 * descendant set is needed by the sidebar, the search scope and the folder
 * size — each walk used to issue one query per level, every time. The
 * container binds this scoped (not shared), so the memo lives exactly as long
 * as the request; flush() covers the case where the same request mutates the
 * tree and then re-renders it.
 */
final class FolderTree
{
    /** @var array<string, bool> */
    private array $containment = [];

    /** @var array<int, list<int>> */
    private array $descendants = [];

    /** @var array<int, int|null> */
    private array $parents = [];

    public function isUnderRoot(Folder $folder, int $rootFolderId): bool
    {
        $folderId = (int) $folder->id;

        $this->rememberParent($folder);

        // ??= caches a false verdict too: only an unset key recomputes.
        return $this->containment[$folderId.':'.$rootFolderId] ??= $this->walksUpTo($folderId, $rootFolderId);
    }

    /**
     * @return list<int>
     */
    public function descendantFolderIdsIncludingRoot(int $rootFolderId): array
    {
        return $this->descendants[$rootFolderId] ??= $this->collectDescendants($rootFolderId);
    }

    public function getDepth(Folder $folder): int
    {
        $this->rememberParent($folder);

        $depth = 0;
        $current = (int) $folder->id;
        $visited = [];

        while ($depth < 100) {
            if (isset($visited[$current])) {
                break;
            }

            $visited[$current] = true;
            $parentId = $this->parentId($current);

            if ($parentId === null) {
                break;
            }

            $depth++;
            $current = $parentId;
        }

        return $depth;
    }

    /**
     * Drops the memo. Required after creating, moving or deleting a folder when
     * the same request goes on to render the tree, otherwise the sidebar and
     * the search scope keep describing the tree as it was.
     */
    public function flush(): void
    {
        $this->containment = [];
        $this->descendants = [];
        $this->parents = [];
    }

    private function walksUpTo(int $folderId, int $rootFolderId): bool
    {
        if ($folderId === $rootFolderId) {
            return true;
        }

        $current = $folderId;

        // A parent_id cycle would otherwise spin forever.
        $visited = [];

        while (! isset($visited[$current])) {
            $visited[$current] = true;
            $parentId = $this->parentId($current);

            if ($parentId === null) {
                return false;
            }

            if ($parentId === $rootFolderId) {
                return true;
            }

            $current = $parentId;
        }

        return false;
    }

    private function parentId(int $folderId): ?int
    {
        if (array_key_exists($folderId, $this->parents)) {
            return $this->parents[$folderId];
        }

        $parentId = Folder::query()->whereKey($folderId)->value('parent_id');

        return $this->parents[$folderId] = $parentId === null ? null : (int) $parentId;
    }

    /**
     * A folder handed in already knows its parent: seeding the memo with it
     * saves the first query of the walk.
     */
    private function rememberParent(Folder $folder): void
    {
        $folderId = (int) $folder->id;

        if (array_key_exists($folderId, $this->parents) || ! $folder->exists) {
            return;
        }

        $this->parents[$folderId] = $folder->parent_id === null ? null : (int) $folder->parent_id;
    }

    /**
     * @return list<int>
     */
    private function collectDescendants(int $rootFolderId): array
    {
        $ids = [$rootFolderId];
        $frontier = [$rootFolderId];

        while ($frontier !== []) {
            $children = Folder::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $ids));
            $ids = array_values(array_unique([...$ids, ...$children]));
        }

        return $ids;
    }
}
