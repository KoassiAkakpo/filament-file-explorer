<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * An older version of a file was made current again.
 *
 * Nothing was destroyed here either: the row that was live is now the newest
 * entry of the history, which is what $replacedMediaId names.
 */
final class FileVersionRestored extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly int $replacedMediaId,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
