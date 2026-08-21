<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * A folder changed parent, taking its whole subtree with it.
 */
final class FolderMoved extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Folder $folder,
        public readonly ?int $fromParentId,
        public readonly int $toParentId,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
