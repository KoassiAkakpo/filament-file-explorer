<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * A folder was duplicated recursively. Fired once for the folder that was asked for, not for each descendant the copy created.
 */
final class FolderCopied extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Folder $copy,
        public readonly Folder $source,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
