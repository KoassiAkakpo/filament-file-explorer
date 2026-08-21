<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file's label changed. The name on disk is unchanged: only Media Library's name attribute is.
 */
final class FileRenamed extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly string $previousName,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
