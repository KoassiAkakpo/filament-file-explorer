<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * A folder came back out of the trash, with the files that went down with it.
 */
final class FolderRestored extends ExplorerEvent
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
