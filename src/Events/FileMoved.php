<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file changed folder. Both folders are under the same root: a move across roots is not something the explorer can express.
 */
final class FileMoved extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly int $fromFolderId,
        public readonly int $toFolderId,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
