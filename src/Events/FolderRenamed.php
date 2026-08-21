<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * A folder's name changed, and its slug with it.
 */
final class FolderRenamed extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Folder $folder,
        public readonly string $previousName,
        public readonly string $previousSlug,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
