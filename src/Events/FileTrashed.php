<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file was moved to the trash collection. It is still on disk and still counts against the quota.
 */
final class FileTrashed extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly bool $withFolder,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
