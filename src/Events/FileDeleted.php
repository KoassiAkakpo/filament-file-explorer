<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A file was destroyed: purged from the trash, or deleted with the trash off.
 *
 * It carries a snapshot rather than the model, because by the time a listener
 * runs the row is gone and Media Library has removed the bytes from the disk.
 * That is also why the size is here — it is the last chance to record it.
 */
final class FileDeleted extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly int $mediaId,
        public readonly string $name,
        public readonly string $fileName,
        public readonly int $folderId,
        public readonly int $size,
        public readonly bool $purgedFromTrash,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
