<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * A folder was soft-deleted, with its subtree and the files in it.
 */
final class FolderTrashed extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Folder $folder,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
