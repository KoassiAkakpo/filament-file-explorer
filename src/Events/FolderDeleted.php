<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A folder was destroyed, with everything under it.
 *
 * A snapshot, for the same reason as FileDeleted. Fired once for the folder the
 * user acted on — not for each descendant the recursive delete took with it,
 * which would turn one click into an unbounded number of events.
 */
final class FolderDeleted extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly int $folderId,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly bool $purgedFromTrash,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
