<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Whether a media row belongs to a scope, and which folder it hangs off.
 *
 * Media ids arrive as user input everywhere — Livewire params, query strings,
 * route bindings, and now a share token — so this answers the one question that
 * gates all of them. Three conditions, and all three matter:
 *
 * - the row's model_type is the Folder morph class,
 * - its collection_name is the explorer's collection,
 * - and its folder sits under the root.
 *
 * Drop either of the first two and a media row of some other model, whose
 * model_id happens to collide with an in-scope folder id, passes containment.
 * Resolve the folder and skip the check when the lookup returns null — which
 * the component once did — and every media row of every other model becomes
 * reachable for rename, move, copy, info and delete.
 *
 * One implementation on purpose. This used to be written out in the controller
 * and in the component, which is how one of the three conditions gets added in
 * one place and forgotten in the other.
 */
final class MediaScope
{
    /**
     * The media's folder when it sits under this root, null when it does not.
     */
    public function folderUnderRoot(Media $media, int $rootFolderId): ?Folder
    {
        return $this->folderUnderAnyRoot($media, [$rootFolderId]);
    }

    /**
     * The same, for a scope that offers several roots. Still one root at a time
     * underneath: the folder has to sit under one of them.
     *
     * @param  list<int>  $rootFolderIds
     */
    public function folderUnderAnyRoot(Media $media, array $rootFolderIds): ?Folder
    {
        $folder = $this->explorerFolder($media);

        if ($folder === null) {
            return null;
        }

        return app(FolderTree::class)->isUnderAnyRoot($folder, $rootFolderIds) ? $folder : null;
    }

    /**
     * The folder a media row hangs off, when the row is one of the explorer's at
     * all. Says nothing yet about which scope it belongs to.
     */
    private function explorerFolder(Media $media, ?string $collection = null): ?Folder
    {
        if ($media->model_type !== FolderModel::morphClass()) {
            return null;
        }

        if ($media->collection_name !== ($collection ?? UploadRules::collection())) {
            return null;
        }

        $folder = FolderModel::query()->find($media->model_id);

        return $folder instanceof Folder ? $folder : null;
    }
}
